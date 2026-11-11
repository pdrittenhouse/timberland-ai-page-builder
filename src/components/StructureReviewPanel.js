import {
    Button,
    Card,
    CardBody,
    Notice,
    SelectControl,
    TextControl,
    TextareaControl,
} from '@wordpress/components';
import { useSelect, useDispatch } from '@wordpress/data';
import { useState, useEffect } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { addQueryArgs } from '@wordpress/url';
import { STORE_NAME } from '../store';

/**
 * Walk to a node by path.
 *
 * Extracted so the field editor and the block swap navigate identically. They had one copy each in
 * the first draft, which is the duplication this subsystem has been bitten by four times -- and a
 * navigation that disagreed with itself would write a value into the wrong block.
 *
 * Path indices map to `children[0..n-1]` then `inner_blocks[n..n+m-1]`, matching the shape
 * /structure emits.
 */
function nodeAt(tree, path) {
    let node = null;
    const roots = tree.blocks || [];

    for (let i = 0; i < path.length; i++) {
        const idx = path[i];

        if (i === 0) {
            node = roots[idx];
        } else {
            const kids = node?.children || [];
            const inner = node?.inner_blocks || [];
            node = idx < kids.length ? kids[idx] : inner[idx - kids.length];
        }

        if (!node) {
            return null;
        }
    }

    return node;
}

/**
 * Renders the block tree from the Structure step for user review/editing.
 * Shows a tree of blocks with editable content fields.
 */
export default function StructureReviewPanel({ postType, postId }) {
    const { blockTree, prompt, isAssembling, isStructuring } = useSelect(
        (select) => ({
            blockTree: select(STORE_NAME).getBlockTree(),
            prompt: select(STORE_NAME).getPrompt(),
            isAssembling: select(STORE_NAME).isAssembling(),
            isStructuring: select(STORE_NAME).isStructuring(),
        }),
        []
    );

    const [swapNote, setSwapNote] = useState('');

    const { setBlockTree, assembleMarkup, clearBlockTree, setError } =
        useDispatch(STORE_NAME);

    if (!blockTree || !blockTree.blocks?.length) {
        return null;
    }

    const isBusy = isAssembling || isStructuring;

    const handleAssemble = () => {
        assembleMarkup(postType, postId);
    };

    const handleBack = () => {
        clearBlockTree();
    };

    /**
     * Replace the BLOCK at a position, keeping the values the new block can hold.
     *
     * UC5. This panel could edit a block's field values and not its identity, so an operator who
     * could see that the wrong block had been chosen could only edit its heading. Measured cause
     * for wanting it: `acf/feature` is placed inside `acf/column` even with its AVOID guidance
     * reaching the model, because prose is a label and not a gate.
     *
     * Values the new block has no field for are DROPPED and reported, not carried. Carrying them
     * would hand the operator validation errors they did not cause; dropping them silently would
     * lose their copy. The surviving set comes from the same field list the model's
     * `get_block_fields` tool serves, so the editor and the model agree on what a block accepts.
     */
    const swapBlock = async (blockPath, nextBlock) => {
        let keep = null;

        try {
            const res = await apiFetch({
                path: addQueryArgs('/taipb/v1/blocks/fields', { block: nextBlock }),
            });
            keep = new Set(res.fields || []);
        } catch {
            // Without the field list we cannot say what survives, so change nothing rather than
            // guess -- a wrong guess here deletes the operator's copy.
            return;
        }

        const updated = JSON.parse(JSON.stringify(blockTree));
        const node = nodeAt(updated, blockPath);

        if (!node) {
            return;
        }

        const dropped = Object.keys(node.data || {}).filter((f) => !keep.has(f));
        const nextData = {};

        Object.entries(node.data || {}).forEach(([field, value]) => {
            if (keep.has(field)) {
                nextData[field] = value;
            }
        });

        node.block = nextBlock;
        node.data = nextData;

        setBlockTree(updated);

        if (dropped.length > 0) {
            setSwapNote(
                `Changed to ${nextBlock}. ${dropped.length} value(s) had no field on it and were removed: ${dropped
                    .slice(0, 6)
                    .join(', ')}${dropped.length > 6 ? '…' : ''}`
            );
        } else {
            setSwapNote(`Changed to ${nextBlock}.`);
        }
    };

    /**
     * Update a field value deep in the block tree.
     * path is an array of indices into children/inner_blocks arrays,
     * ending with the field name.
     */
    const updateBlockField = (blockPath, fieldName, value) => {
        const updated = JSON.parse(JSON.stringify(blockTree));
        const node = nodeAt(updated, blockPath);

        if (node) {
            if (fieldName === '_content') {
                node.content = value;
            } else if (fieldName === '_level') {
                node.level = parseInt(value, 10) || 2;
            } else if (fieldName === '_text') {
                node.text = value;
            } else {
                if (!node.data) {
                    node.data = {};
                }
                node.data[fieldName] = value;
            }
        }

        setBlockTree(updated);
    };

    return (
        <div className="taipb-structure-review">
            <p className="taipb-structure-review-heading">
                Block structure:
            </p>

            <div className="taipb-structure-tree">
                {swapNote && (
                    <Notice
                        status="info"
                        onRemove={() => setSwapNote('')}
                    >
                        {swapNote}
                    </Notice>
                )}
                {blockTree.blocks.map((block, i) => (
                    <BlockNode
                        key={i}
                        node={block}
                        path={[i]}
                        depth={0}
                        onUpdate={updateBlockField}
                        onSwap={swapBlock}
                        parent=""
                        ancestors={[]}
                        disabled={isBusy}
                    />
                ))}
            </div>

            <div className="taipb-structure-review-actions">
                <Button
                    variant="primary"
                    onClick={handleAssemble}
                    disabled={isBusy}
                    isBusy={isBusy}
                >
                    {isAssembling ? 'Assembling...' : 'Assemble & Preview'}
                </Button>
                <Button
                    variant="tertiary"
                    onClick={handleBack}
                    disabled={isBusy}
                >
                    Back to plan
                </Button>
            </div>
        </div>
    );
}

/**
 * What may legally sit at this position, asked of the server.
 *
 * The full manifest is 1.4 MB on a 48-block install -- 14,972 field definitions -- so the list is
 * fetched per position rather than held in the editor. ~2.4 KB a call.
 *
 * Filtered server-side by the same three placement primitives the validator enforces, so the
 * dropdown and the validator cannot disagree. Offering all 48 and letting validation reject the
 * illegal ones would be worse than offering nothing, because the operator would reasonably trust
 * the list.
 *
 * Returns an empty list on failure, which renders no selector at all -- the panel degrades to what
 * it did before rather than showing a control that cannot be trusted.
 */
function usePlaceableBlocks(parent, ancestors) {
    const [blocks, setBlocks] = useState([]);
    const key = `${parent}|${ancestors.join(',')}`;

    useEffect(() => {
        // `null` means the picker is closed. Fetching for every node on mount would issue one
        // request per block in the tree for lists nobody has asked to see.
        if (parent === null) {
            return undefined;
        }

        let live = true;

        apiFetch({
            path: addQueryArgs('/taipb/v1/blocks/placeable', { parent, ancestors }),
        })
            .then((res) => {
                if (live) {
                    setBlocks(res.blocks || []);
                }
            })
            .catch(() => {
                if (live) {
                    setBlocks([]);
                }
            });

        return () => {
            live = false;
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [key]);

    return blocks;
}

/**
 * The block's identity, as a control rather than a label.
 *
 * Deliberately starts as plain text and only becomes a dropdown when asked. A select on every node
 * of a 30-block tree is a wall of dropdowns, and the common case is that the choice was right --
 * the operator needs to CHANGE one, not confirm thirty.
 *
 * A core block is shown but not swappable: it has no ACF fields, so there is nothing to carry into
 * a replacement, and the honest signal is the warning validation already raises about it.
 */
function BlockPicker({ current, shortName, parent, ancestors, disabled, onSwap }) {
    const [open, setOpen] = useState(false);
    const placeable = usePlaceableBlocks(open ? parent : null, ancestors);

    if (current.startsWith('core/')) {
        return <span className="taipb-block-node-name">{shortName}</span>;
    }

    if (!open) {
        return (
            <Button
                variant="link"
                className="taipb-block-node-name taipb-block-node-name--editable"
                onClick={() => setOpen(true)}
                disabled={disabled}
                // The affordance has to say what clicking does; "section" alone reads as a label.
                label={`Change the block used here (currently ${shortName})`}
                showTooltip
            >
                {shortName}
            </Button>
        );
    }

    // Still loading, or nothing legal here -- either way a dropdown of one option is noise.
    if (placeable.length === 0) {
        return <span className="taipb-block-node-name">{shortName}</span>;
    }

    return (
        <SelectControl
            className="taipb-block-node-picker"
            value={current}
            disabled={disabled}
            options={placeable.map((b) => ({
                label: b.title || b.name.replace('acf/', ''),
                value: b.name,
            }))}
            onChange={(next) => {
                setOpen(false);

                if (next !== current) {
                    onSwap(next);
                }
            }}
            __nextHasNoMarginBottom
        />
    );
}

/**
 * Renders a single block node in the tree, recursively rendering children.
 */
function BlockNode({ node, path, depth, onUpdate, onSwap, parent = '', ancestors = [], disabled }) {
    const blockName = node.block || '';
    const shortName = blockName.replace('acf/', '').replace('core/', '');
    const isCore = blockName.startsWith('core/');
    const data = node.data || {};
    const children = node.children || [];
    const innerBlocks = node.inner_blocks || [];

    // Layout scaffolding blocks (section/row/column) — show collapsed
    const isScaffold = ['acf/section', 'acf/row', 'acf/column'].includes(blockName);

    // Determine which fields to show for editing
    const editableFields = isCore
        ? getCoreEditableFields(node)
        : getAcfEditableFields(data, blockName);

    return (
        <div
            className={`taipb-block-node taipb-block-node--depth-${Math.min(depth, 4)}`}
        >
            <div className="taipb-block-node-header">
                <BlockPicker
                    current={blockName}
                    shortName={shortName}
                    parent={parent}
                    ancestors={ancestors}
                    disabled={disabled}
                    onSwap={(next) => onSwap(path, next)}
                />
                {isScaffold && data.col_width_0_width && (
                    <span className="taipb-block-node-meta">
                        col: {data.col_width_0_width}/12
                    </span>
                )}
            </div>

            {/* Editable fields */}
            {!isScaffold && editableFields.length > 0 && (
                <div className="taipb-block-node-fields">
                    {editableFields.map((field) => (
                        <FieldEditor
                            key={field.name}
                            field={field}
                            path={path}
                            onUpdate={onUpdate}
                            disabled={disabled}
                        />
                    ))}
                </div>
            )}

            {/* Children (structural nesting) */}
            {children.length > 0 && (
                <div className="taipb-block-node-children">
                    {children.map((child, i) => (
                        <BlockNode
                            key={i}
                            node={child}
                            path={[...path, i]}
                            depth={depth + 1}
                            onUpdate={onUpdate}
                            onSwap={onSwap}
                            parent={blockName}
                            ancestors={[...ancestors, blockName]}
                            disabled={disabled}
                        />
                    ))}
                </div>
            )}

            {/* Inner blocks (content blocks inside containers) */}
            {innerBlocks.length > 0 && (
                <div className="taipb-block-node-innerblocks">
                    {innerBlocks.map((ib, i) => {
                        // Inner blocks use a separate index space
                        // We need to map them to the correct position in the parent
                        const ibIndex = children.length + i;
                        return (
                            <BlockNode
                                key={`ib-${i}`}
                                node={ib}
                                path={[...path, ibIndex]}
                                depth={depth + 1}
                                onUpdate={onUpdate}
                                onSwap={onSwap}
                                parent={blockName}
                                ancestors={[...ancestors, blockName]}
                                disabled={disabled}
                            />
                        );
                    })}
                </div>
            )}
        </div>
    );
}

/**
 * Render an individual field editor.
 */
function FieldEditor({ field, path, onUpdate, disabled }) {
    const isLong =
        field.type === 'wysiwyg' ||
        field.type === 'textarea' ||
        (typeof field.value === 'string' && field.value.length > 80);

    if (isLong) {
        return (
            <TextareaControl
                label={field.label}
                value={field.value}
                onChange={(v) => onUpdate(path, field.name, v)}
                rows={3}
                disabled={disabled}
                className="taipb-block-field"
            />
        );
    }

    return (
        <TextControl
            label={field.label}
            value={typeof field.value === 'string' ? field.value : String(field.value ?? '')}
            onChange={(v) => onUpdate(path, field.name, v)}
            disabled={disabled}
            className="taipb-block-field"
        />
    );
}

/**
 * Get editable fields for a core block.
 */
function getCoreEditableFields(node) {
    const fields = [];
    const type = (node.block || '').replace('core/', '');

    if (type === 'heading') {
        fields.push({
            name: '_content',
            label: 'Heading text',
            value: node.content || '',
            type: 'text',
        });
        fields.push({
            name: '_level',
            label: 'Level (1-6)',
            value: String(node.level || 2),
            type: 'text',
        });
    } else if (type === 'paragraph') {
        fields.push({
            name: '_content',
            label: 'Paragraph text',
            value: node.content || '',
            type: 'textarea',
        });
    } else if (type === 'button') {
        fields.push({
            name: '_text',
            label: 'Button text',
            value: node.text || '',
            type: 'text',
        });
    }

    return fields;
}

/**
 * Get editable fields for an ACF block.
 * Filters out internal/styling fields to show only content-relevant ones.
 */
function getAcfEditableFields(data, blockName) {
    const skipFields = new Set([
        'mode',
        'alignText',
        'alignContent',
        'col_width',
        'col_width_0_width',
        'col_width_0_breakpoint',
    ]);

    const skipPrefixes = ['padding_', 'margin_', 'bg_'];

    const fields = [];

    for (const [key, value] of Object.entries(data)) {
        // Skip companion keys
        if (key.startsWith('_')) continue;
        // Skip known internal fields
        if (skipFields.has(key)) continue;
        // Skip styling fields
        if (skipPrefixes.some((p) => key.startsWith(p))) continue;
        // Skip empty values
        if (value === '' || value === null || value === undefined) continue;
        // Skip non-string/non-number values (objects, arrays)
        if (typeof value === 'object') continue;

        const label = key
            .replace(/_/g, ' ')
            .replace(/\b\w/g, (l) => l.toUpperCase());

        const isHtml =
            key.includes('body') ||
            key.includes('wysiwyg') ||
            key.includes('content');

        fields.push({
            name: key,
            label,
            value: String(value),
            type: isHtml ? 'wysiwyg' : 'text',
        });
    }

    return fields;
}
