import { Button, RadioControl, TextControl } from '@wordpress/components';
import { useSelect, useDispatch } from '@wordpress/data';
import { STORE_NAME } from '../store';

export default function ClarificationPanel({ postType, postId }) {
    const { prompt, questions, answers, selectedPattern, isGenerating } =
        useSelect(
            (select) => ({
                prompt: select(STORE_NAME).getPrompt(),
                questions: select(STORE_NAME).getClarificationQuestions(),
                answers: select(STORE_NAME).getClarificationAnswers(),
                selectedPattern: select(STORE_NAME).getSelectedPattern(),
                isGenerating: select(STORE_NAME).isGenerating(),
            }),
            []
        );

    const { setClarificationAnswer, generateWithPattern, clearClarification } =
        useDispatch(STORE_NAME);

    if (!questions || questions.length === 0) {
        return null;
    }

    const handleGenerate = () => {
        generateWithPattern(
            prompt,
            postType,
            postId,
            selectedPattern,
            answers
        );
    };

    const handleCancel = () => {
        clearClarification();
    };

    // Check if generate should be disabled (e.g., provide_name/provide_url selected but no value entered)
    const isAnswerIncomplete = questions.some((q) => {
        const answer = answers[q.id] || q.default || '';
        if (answer === 'provide_name' || answer === 'provide_url') {
            return !answers[`${q.id}_value`]?.trim();
        }
        return false;
    });

    return (
        <div className="taipb-clarification">
            <p className="taipb-clarification-heading">
                A few questions before generating:
            </p>

            {questions.map((q) => {
                const selectedValue = answers[q.id] || q.default || '';
                const showTextInput =
                    selectedValue === 'provide_name' ||
                    selectedValue === 'provide_url';

                return (
                    <div key={q.id} className="taipb-clarification-question">
                        <RadioControl
                            label={q.question}
                            selected={selectedValue}
                            options={q.options.map((opt) => ({
                                label: opt.label,
                                value: opt.value,
                            }))}
                            onChange={(value) =>
                                setClarificationAnswer(q.id, value)
                            }
                        />
                        {showTextInput && (
                            <TextControl
                                label={
                                    selectedValue === 'provide_name'
                                        ? 'Image filename'
                                        : 'Image URL'
                                }
                                placeholder={
                                    selectedValue === 'provide_name'
                                        ? 'e.g., my-hero-image.jpg'
                                        : 'https://example.com/image.jpg'
                                }
                                value={answers[`${q.id}_value`] || ''}
                                onChange={(val) =>
                                    setClarificationAnswer(
                                        `${q.id}_value`,
                                        val
                                    )
                                }
                                className="taipb-clarification-text-input"
                            />
                        )}
                    </div>
                );
            })}

            <div className="taipb-clarification-actions">
                <Button
                    variant="primary"
                    onClick={handleGenerate}
                    disabled={isGenerating || isAnswerIncomplete}
                    isBusy={isGenerating}
                >
                    {isGenerating ? 'Generating...' : 'Generate'}
                </Button>
                <Button
                    variant="tertiary"
                    onClick={handleCancel}
                    disabled={isGenerating}
                >
                    Cancel
                </Button>
            </div>
        </div>
    );
}
