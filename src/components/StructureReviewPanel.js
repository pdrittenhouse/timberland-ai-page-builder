import { Button, Card, CardBody, TextControl, TextareaControl } from '@wordpress/components';
import { useSelect, useDispatch } from '@wordpress/data';
import { STORE_NAME } from '../store';

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
     * Update a field value deep in the block tree.
     * path is an array of indices into children/inner_blocks arrays,
     * ending with the field name.
     */
    const updateBlockField = (blockPath, fieldName, value) => {
        const updated = JSON.parse(JSON.stringify(blockTree));
        let node = null;

        // Navigate to the target block through the tree
        // Path indices map to: children[0..n-1], then inner_blocks[n..n+m-1]
        let current = updated.blocks;
        for (let i = 0; i < blockPath.length; i++) {
            const idx = blockPath[i];
            if (i === 0) {
                node = current[idx];
            } else {
                const childrenArr = node.children || [];
                const innerBlocksArr = node.inner_blocks || [];
                if (idx < childrenArr.length) {
                    node = childrenArr[idx];
                } else {
                    node = innerBlocksArr[idx - childrenArr.length];
                }
            }
        }

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
                {blockTree.blocks.map((block, i) => (
                    <BlockNode
                        key={i}
                        node={block}
                        path={[i]}
                        depth={0}
                        onUpdate={updateBlockField}
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
 * Renders a single block node in the tree, recursively rendering children.
 */
function BlockNode({ node, path, depth, onUpdate, disabled }) {
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
                <span className="taipb-block-node-name">{shortName}</span>
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
