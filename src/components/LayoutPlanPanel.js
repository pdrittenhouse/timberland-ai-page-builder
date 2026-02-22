import { Button, Card, CardBody, TextControl } from '@wordpress/components';
import { useSelect, useDispatch } from '@wordpress/data';
import { STORE_NAME } from '../store';

export default function LayoutPlanPanel({ postType, postId }) {
    const {
        decomposition,
        prompt,
        isGenerating,
        isAnalyzing,
        isStructuring,
        generationMode,
        blockTree,
    } = useSelect(
        (select) => ({
            decomposition: select(STORE_NAME).getDecomposition(),
            prompt: select(STORE_NAME).getPrompt(),
            isGenerating: select(STORE_NAME).isGenerating(),
            isAnalyzing: select(STORE_NAME).isAnalyzing(),
            isStructuring: select(STORE_NAME).isStructuring(),
            generationMode: select(STORE_NAME).getGenerationMode(),
            blockTree: select(STORE_NAME).getBlockTree(),
        }),
        []
    );

    const {
        setDecomposition,
        analyzePatterns,
        generateWithPatterns,
        generateStructure,
    } = useDispatch(STORE_NAME);

    // Hide if no decomposition, or if block tree is already generated (showing StructureReviewPanel)
    if (!decomposition || !decomposition.sections?.length || blockTree) {
        return null;
    }

    const isBusy = isGenerating || isAnalyzing || isStructuring;

    const handleConfirm = () => {
        if (generationMode === 'multi-step') {
            // Multi-step: generate block tree structure
            generateStructure(prompt, postType, postId);
        } else {
            // Direct mode: go through pattern analysis → generate
            const patternIds = decomposition.sections
                .map((s) => s.pattern_id)
                .filter(Boolean);

            if (patternIds.length > 0) {
                analyzePatterns(prompt, postType, postId, patternIds);
            } else {
                generateWithPatterns(prompt, postType, postId, []);
            }
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
                    {isBusy
                        ? isStructuring
                            ? 'Building structure...'
                            : 'Processing...'
                        : generationMode === 'multi-step'
                            ? 'Build block structure'
                            : 'Generate from this plan'}
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
