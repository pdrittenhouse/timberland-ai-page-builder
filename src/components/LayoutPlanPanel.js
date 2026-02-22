import { Button, Card, CardBody, TextControl } from '@wordpress/components';
import { useSelect, useDispatch } from '@wordpress/data';
import { STORE_NAME } from '../store';

export default function LayoutPlanPanel({ postType, postId }) {
    const { decomposition, prompt, isGenerating, isAnalyzing } = useSelect(
        (select) => ({
            decomposition: select(STORE_NAME).getDecomposition(),
            prompt: select(STORE_NAME).getPrompt(),
            isGenerating: select(STORE_NAME).isGenerating(),
            isAnalyzing: select(STORE_NAME).isAnalyzing(),
        }),
        []
    );

    const { setDecomposition, analyzePatterns, generateWithPatterns } =
        useDispatch(STORE_NAME);

    if (!decomposition || !decomposition.sections?.length) {
        return null;
    }

    const isBusy = isGenerating || isAnalyzing;

    const handleConfirm = () => {
        const patternIds = decomposition.sections
            .map((s) => s.pattern_id)
            .filter(Boolean);

        if (patternIds.length > 0) {
            analyzePatterns(prompt, postType, postId, patternIds);
        } else {
            generateWithPatterns(prompt, postType, postId, []);
        }
    };

    const handleEditSection = (index, field, value) => {
        const updated = {
            ...decomposition,
            sections: decomposition.sections.map((s, i) =>
                i === index
                    ? { ...s, content: { ...s.content, [field]: value } }
                    : s
            ),
        };
        setDecomposition(updated);
    };

    const handleRemoveSection = (index) => {
        const sections = decomposition.sections.filter((_, i) => i !== index);
        setDecomposition({
            ...decomposition,
            sections,
            suggested_pattern_ids: sections
                .map((s) => s.pattern_id)
                .filter(Boolean),
        });
    };

    const handleDiscard = () => {
        setDecomposition(null);
    };

    return (
        <div className="taipb-layout-plan">
            <p className="taipb-layout-plan-heading">
                Proposed layout plan:
            </p>

            {decomposition.sections.map((section, i) => (
                <Card
                    key={i}
                    size="small"
                    className="taipb-layout-plan-section"
                >
                    <CardBody>
                        <div className="taipb-layout-plan-section-header">
                            <strong>
                                Section {i + 1}: {section.intent}
                            </strong>
                            {section.pattern_hint && (
                                <span className="taipb-layout-plan-pattern">
                                    {section.pattern_hint}
                                </span>
                            )}
                        </div>
                        {section.content &&
                            Object.entries(section.content).map(
                                ([key, value]) =>
                                    value && (
                                        <TextControl
                                            key={key}
                                            label={key}
                                            value={value}
                                            onChange={(v) =>
                                                handleEditSection(i, key, v)
                                            }
                                            disabled={isBusy}
                                        />
                                    )
                            )}
                        <Button
                            variant="tertiary"
                            size="small"
                            isDestructive
                            onClick={() => handleRemoveSection(i)}
                            disabled={isBusy}
                        >
                            Remove section
                        </Button>
                    </CardBody>
                </Card>
            ))}

            <div className="taipb-layout-plan-actions">
                <Button
                    variant="primary"
                    onClick={handleConfirm}
                    disabled={isBusy}
                    isBusy={isBusy}
                    className="taipb-layout-plan-confirm"
                >
                    {isBusy ? 'Processing...' : 'Generate from this plan'}
                </Button>
                <Button
                    variant="tertiary"
                    onClick={handleDiscard}
                    disabled={isBusy}
                    className="taipb-layout-plan-discard"
                >
                    Discard plan
                </Button>
            </div>
        </div>
    );
}
