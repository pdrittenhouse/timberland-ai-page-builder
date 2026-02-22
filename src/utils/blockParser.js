import { parse, createBlock } from '@wordpress/blocks';
import { dispatch, select } from '@wordpress/data';

/**
 * Parse markup string into block objects and insert into the editor.
 *
 * @param {string} markup - The block markup to insert.
 * @param {string} mode - 'append' | 'insert' | 'replace'
 * @returns {{ success: boolean, blockCount: number, error?: string }}
 */
export function insertMarkup(markup, mode = 'append') {
    try {
        const blocks = parse(markup);

        if (!blocks || blocks.length === 0) {
            return { success: false, blockCount: 0, error: 'No valid blocks found in markup.' };
        }

        // Filter out empty freeform blocks (whitespace-only)
        const validBlocks = blocks.filter(
            (block) => block.name !== 'core/freeform' || block.attributes?.content?.trim()
        );

        if (validBlocks.length === 0) {
            return { success: false, blockCount: 0, error: 'No valid blocks found in markup.' };
        }

        const editor = dispatch('core/block-editor');
        const { getBlocks, getSelectedBlockClientId } = select('core/block-editor');

        switch (mode) {
            case 'replace': {
                // Remove all existing blocks, then insert
                const existingBlocks = getBlocks();
                const clientIds = existingBlocks.map((b) => b.clientId);
                if (clientIds.length > 0) {
                    editor.removeBlocks(clientIds);
                }
                editor.insertBlocks(validBlocks);
                break;
            }

            case 'insert': {
                // Insert after the currently selected block
                const selectedId = getSelectedBlockClientId();
                if (selectedId) {
                    const allBlocks = getBlocks();
                    const index = allBlocks.findIndex((b) => b.clientId === selectedId);
                    editor.insertBlocks(validBlocks, index + 1);
                } else {
                    // No selection — append
                    editor.insertBlocks(validBlocks);
                }
                break;
            }

            case 'append':
            default: {
                editor.insertBlocks(validBlocks);
                break;
            }
        }

        return { success: true, blockCount: validBlocks.length };
    } catch (err) {
        return {
            success: false,
            blockCount: 0,
            error: err.message || 'Failed to parse and insert blocks.',
        };
    }
}
