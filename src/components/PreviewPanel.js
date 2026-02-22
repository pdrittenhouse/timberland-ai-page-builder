import { useMemo, useState } from '@wordpress/element';
import { Button, Notice, __experimentalText as Text } from '@wordpress/components';
import { useSelect } from '@wordpress/data';
import { BlockPreview } from '@wordpress/block-editor';
import { parse } from '@wordpress/blocks';
import { STORE_NAME } from '../store';
import { cleanMarkup } from '../utils/blockParser';

export default function PreviewPanel() {
    const { markup, validation, apiResponse, error } = useSelect(
        (select) => ({
            markup: select(STORE_NAME).getGeneratedMarkup(),
            validation: select(STORE_NAME).getValidationResult(),
            apiResponse: select(STORE_NAME).getApiResponse(),
            error: select(STORE_NAME).getError(),
        }),
        []
    );

    const [showVisualPreview, setShowVisualPreview] = useState(false);

    const blocks = useMemo(() => {
        if (!markup || !showVisualPreview) return [];
        try {
            return parse(cleanMarkup(markup)).filter(
                (block) =>
                    block.name !== 'core/freeform' ||
                    block.attributes.content?.trim()
            );
        } catch {
            return [];
        }
    }, [markup, showVisualPreview]);

    // Limit blocks sent to BlockPreview to avoid memory exhaustion
    // in the WordPress block parser during server-side rendering.
    const MAX_PREVIEW_BLOCKS = 5;
    const previewBlocks = blocks.slice(0, MAX_PREVIEW_BLOCKS);
    const previewTruncated = blocks.length > MAX_PREVIEW_BLOCKS;

    if (error) {
        return (
            <Notice status="error" isDismissible={false}>
                {error}
            </Notice>
        );
    }

    if (!markup) {
        return null;
    }

    return (
        <div className="taipb-preview-panel">
            {/* Validation status */}
            {validation && (
                <div className="taipb-validation">
                    <Notice
                        status={validation.valid ? 'success' : 'warning'}
                        isDismissible={false}
                    >
                        {validation.valid
                            ? `Valid markup — ${validation.block_count} blocks`
                            : `${validation.errors.length} validation error(s)`}
                    </Notice>

                    {validation.errors.length > 0 && (
                        <ul className="taipb-validation-errors">
                            {validation.errors.map((err, i) => (
                                <li key={i}>{err}</li>
                            ))}
                        </ul>
                    )}

                    {validation.warnings.length > 0 && (
                        <ul className="taipb-validation-warnings">
                            {validation.warnings.map((warn, i) => (
                                <li key={i}>{warn}</li>
                            ))}
                        </ul>
                    )}
                </div>
            )}

            {/* Visual preview — opt-in to avoid memory exhaustion from SSR */}
            {!showVisualPreview && (
                <Button
                    variant="secondary"
                    size="small"
                    onClick={() => setShowVisualPreview(true)}
                    className="taipb-show-preview-button"
                >
                    Show visual preview
                </Button>
            )}

            {showVisualPreview && previewBlocks.length > 0 && (
                <div className="taipb-visual-preview">
                    <BlockPreview
                        blocks={previewBlocks}
                        viewportWidth={1200}
                    />
                    {previewTruncated && (
                        <p style={{ padding: '8px 12px', fontSize: '11px', color: '#757575', margin: 0 }}>
                            Preview showing first {MAX_PREVIEW_BLOCKS} of {blocks.length} blocks.
                        </p>
                    )}
                </div>
            )}

            {/* Token usage */}
            {apiResponse && (
                <div className="taipb-token-usage">
                    <Text size="small" isBlock>
                        Model: {apiResponse.model}
                    </Text>
                    <Text size="small" isBlock>
                        Tokens: {apiResponse.input_tokens.toLocaleString()} in /{' '}
                        {apiResponse.output_tokens.toLocaleString()} out
                    </Text>
                </div>
            )}

            {/* Raw markup toggle */}
            <details className="taipb-markup-preview">
                <summary>View raw markup</summary>
                <pre className="taipb-markup-code">{markup}</pre>
            </details>
        </div>
    );
}
