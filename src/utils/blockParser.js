import { parse, createBlock } from '@wordpress/blocks';
import { dispatch, select } from '@wordpress/data';

/**
 * Clean markup before parsing: strip markdown fences, leading/trailing
 * non-block text, and other LLM output artifacts that prevent the
 * WordPress block grammar parser from recognizing block comments.
 *
 * @param {string} raw - Raw markup from the API response.
 * @returns {string} Cleaned markup containing only block comments.
 */
function cleanMarkup(raw) {
    let markup = raw;

    // Strip markdown code fences (```html, ```xml, ``` etc.)
    markup = markup.replace(/^```(?:html|xml|php|plaintext)?\s*\n?/gim, '');
    markup = markup.replace(/\n?```\s*$/gm, '');

    // Remove any text before the first block comment
    const firstBlock = markup.indexOf('<!-- wp:');
    if (firstBlock > 0) {
        markup = markup.substring(firstBlock);
    }

    // Remove any text after the last closing comment (--> or /-->)
    const lastClose = markup.lastIndexOf('-->');
    if (lastClose > 0) {
        markup = markup.substring(0, lastClose + 3);
    }

    return markup.trim();
}

/**
 * Parse markup string into block objects and insert into the editor.
 *
 * @param {string} markup - The block markup to insert.
 * @param {string} mode - 'append' | 'insert' | 'replace'
 * @returns {{ success: boolean, blockCount: number, error?: string }}
 */
export { cleanMarkup };

export function insertMarkup(markup, mode = 'append') {
    try {
        const cleaned = cleanMarkup(markup);
        const blocks = parse(cleaned);

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
