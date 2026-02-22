import { Button, Card, CardBody, CardHeader } from '@wordpress/components';
import { useSelect, useDispatch } from '@wordpress/data';
import { STORE_NAME } from '../store';

export default function PatternMatch({ postType, postId }) {
    const { prompt, matches, isGenerating, isAnalyzing } = useSelect(
        (select) => ({
            prompt: select(STORE_NAME).getPrompt(),
            matches: select(STORE_NAME).getPatternMatches(),
            isGenerating: select(STORE_NAME).isGenerating(),
            isAnalyzing: select(STORE_NAME).isAnalyzing(),
        }),
        []
    );

    const { analyzePattern, generateWithPattern, clearMatches } =
        useDispatch(STORE_NAME);

    if (!matches || matches.length === 0) {
        return null;
    }

    const isBusy = isGenerating || isAnalyzing;

    const handleUsePattern = (patternId) => {
        // Run analysis first to check for ambiguities
        analyzePattern(prompt, postType, postId, patternId);
    };

    const handleSkip = () => {
        generateWithPattern(prompt, postType, postId, null);
    };

    const handleCancel = () => {
        clearMatches();
    };

    return (
        <div className="taipb-pattern-match">
            <p className="taipb-pattern-match-heading">
                We found matching patterns/layouts. Use one as a base?
            </p>

            <div className="taipb-pattern-match-list">
                {matches.map((match) => (
                    <Card
                        key={match.id}
                        size="small"
                        className="taipb-pattern-match-item"
                    >
                        <CardHeader size="small">
                            <span className="taipb-pattern-match-title">
                                {match.title}
                            </span>
                            <span className="taipb-pattern-match-type">
                                {match.type}
                            </span>
                        </CardHeader>
                        <CardBody size="small">
                            <Button
                                variant="primary"
                                size="small"
                                onClick={() => handleUsePattern(match.id)}
                                disabled={isBusy}
                                isBusy={isAnalyzing}
                                className="taipb-pattern-use-button"
                            >
                                {isAnalyzing
                                    ? 'Analyzing...'
                                    : 'Use this pattern'}
                            </Button>
                        </CardBody>
                    </Card>
                ))}
            </div>

            <div className="taipb-pattern-match-actions">
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
