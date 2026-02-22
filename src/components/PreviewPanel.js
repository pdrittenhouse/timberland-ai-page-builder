import { useMemo } from '@wordpress/element';
import { Notice, __experimentalText as Text } from '@wordpress/components';
import { useSelect } from '@wordpress/data';
import { BlockPreview } from '@wordpress/block-editor';
import { parse } from '@wordpress/blocks';
import { STORE_NAME } from '../store';

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

    const blocks = useMemo(() => {
        if (!markup) return [];
        try {
            return parse(markup).filter(
                (block) =>
                    block.name !== 'core/freeform' ||
                    block.attributes.content?.trim()
            );
        } catch {
            return [];
        }
    }, [markup]);

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

            {/* Live visual preview */}
            {blocks.length > 0 && (
                <div className="taipb-visual-preview">
                    <BlockPreview
                        blocks={blocks}
                        viewportWidth={1200}
                    />
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
