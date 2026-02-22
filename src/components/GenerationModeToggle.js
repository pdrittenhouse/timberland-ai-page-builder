import { ToggleControl } from '@wordpress/components';
import { useSelect, useDispatch } from '@wordpress/data';
import { STORE_NAME } from '../store';

export default function GenerationModeToggle() {
    const mode = useSelect(
        (select) => select(STORE_NAME).getGenerationMode(),
        []
    );

    const { setGenerationMode } = useDispatch(STORE_NAME);

    return (
        <div className="taipb-mode-toggle">
            <ToggleControl
                label="Multi-step generation"
                help={
                    mode === 'multi-step'
                        ? 'Plan, review structure, then assemble. More accurate.'
                        : 'Generate markup in a single LLM call. Faster but less precise.'
                }
                checked={mode === 'multi-step'}
                onChange={(checked) =>
                    setGenerationMode(checked ? 'multi-step' : 'direct')
                }
            />
        </div>
    );
}
