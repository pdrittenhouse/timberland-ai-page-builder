import { TextareaControl, Button } from '@wordpress/components';
import { useSelect, useDispatch } from '@wordpress/data';
import { STORE_NAME } from '../store';

export default function PromptInput({ postType, postId }) {
    const { prompt, isGenerating, isMatching, isAnalyzing } = useSelect(
        (select) => ({
            prompt: select(STORE_NAME).getPrompt(),
            isGenerating: select(STORE_NAME).isGenerating(),
            isMatching: select(STORE_NAME).isMatching(),
            isAnalyzing: select(STORE_NAME).isAnalyzing(),
        }),
        []
    );

    const { setPrompt, checkMatches } = useDispatch(STORE_NAME);

    const isBusy = isGenerating || isMatching || isAnalyzing;

    const handleGenerate = () => {
        if (!prompt.trim() || isBusy) return;
        checkMatches(prompt, postType, postId);
    };

    const handleKeyDown = (e) => {
        if (e.key === 'Enter' && (e.metaKey || e.ctrlKey)) {
            handleGenerate();
        }
    };

    const buttonLabel = isMatching
        ? 'Finding patterns...'
        : isAnalyzing
            ? 'Analyzing...'
            : isGenerating
                ? 'Generating...'
                : 'Generate Layout';

    return (
        <div className="taipb-prompt-input">
            <TextareaControl
                label="Describe the layout you want"
                help="e.g., Create a hero section with a heading, subtitle, and CTA button, followed by a 3-column card grid."
                value={prompt}
                onChange={setPrompt}
                onKeyDown={handleKeyDown}
                rows={4}
                disabled={isBusy}
            />
            <Button
                variant="primary"
                onClick={handleGenerate}
                isBusy={isBusy}
                disabled={!prompt.trim() || isBusy}
                className="taipb-generate-button"
            >
                {buttonLabel}
            </Button>
        </div>
    );
}
