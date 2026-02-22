import {
    Button,
    Card,
    CardHeader,
    CheckboxControl,
} from '@wordpress/components';
import { useSelect, useDispatch } from '@wordpress/data';
import { STORE_NAME } from '../store';

export default function PatternMatch({ postType, postId }) {
    const { prompt, matches, selectedPatterns, isGenerating, isAnalyzing } =
        useSelect(
            (select) => ({
                prompt: select(STORE_NAME).getPrompt(),
                matches: select(STORE_NAME).getPatternMatches(),
                selectedPatterns: select(STORE_NAME).getSelectedPatterns(),
                isGenerating: select(STORE_NAME).isGenerating(),
                isAnalyzing: select(STORE_NAME).isAnalyzing(),
            }),
            []
        );

    const {
        togglePattern,
        analyzePatterns,
        generateWithPatterns,
        clearMatches,
    } = useDispatch(STORE_NAME);

    if (!matches || matches.length === 0) {
        return null;
    }

    const isBusy = isGenerating || isAnalyzing;

    const handleConfirmSelection = () => {
        analyzePatterns(prompt, postType, postId, selectedPatterns);
    };

    const handleSkip = () => {
        generateWithPatterns(prompt, postType, postId, []);
    };

    const handleCancel = () => {
        clearMatches();
    };

    return (
        <div className="taipb-pattern-match">
            <p className="taipb-pattern-match-heading">
                Select patterns to use as a base:
            </p>

            <div className="taipb-pattern-match-list">
                {matches.map((match) => {
                    const isSelected = selectedPatterns.includes(match.id);
                    return (
                        <Card
                            key={match.id}
                            size="small"
                            className={`taipb-pattern-match-item${isSelected ? ' taipb-pattern-match-item--selected' : ''}`}
                            onClick={() => !isBusy && togglePattern(match.id)}
                        >
                            <CardHeader size="small">
                                <CheckboxControl
                                    __nextHasNoMarginBottom
                                    checked={isSelected}
                                    onChange={() => togglePattern(match.id)}
                                    disabled={isBusy}
                                />
                                <span className="taipb-pattern-match-title">
                                    {match.title}
                                </span>
                                <span className="taipb-pattern-match-type">
                                    {match.type}
                                </span>
                            </CardHeader>
                        </Card>
                    );
                })}
            </div>

            <div className="taipb-pattern-match-actions">
                <Button
                    variant="primary"
                    onClick={handleConfirmSelection}
                    disabled={isBusy || selectedPatterns.length === 0}
                    isBusy={isAnalyzing}
                    className="taipb-pattern-confirm-button"
                >
                    {isAnalyzing
                        ? 'Analyzing...'
                        : `Use ${selectedPatterns.length || ''} selected pattern${selectedPatterns.length !== 1 ? 's' : ''}`}
                </Button>
                <Button
                    variant="secondary"
                    onClick={handleSkip}
                    disabled={isBusy}
                    isBusy={isGenerating}
                    className="taipb-pattern-skip-button"
                >
                    {isGenerating
                        ? 'Generating...'
                        : 'Generate from scratch instead'}
                </Button>
                <Button
                    variant="tertiary"
                    onClick={handleCancel}
                    disabled={isBusy}
                    className="taipb-pattern-cancel-button"
                >
                    Cancel
                </Button>
            </div>
        </div>
    );
}
