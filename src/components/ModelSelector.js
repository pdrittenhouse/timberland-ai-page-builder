import { SelectControl } from '@wordpress/components';
import { useSelect, useDispatch } from '@wordpress/data';
import { STORE_NAME } from '../store';

export default function ModelSelector() {
    const model = useSelect((select) => select(STORE_NAME).getModel(), []);
    const { setModel } = useDispatch(STORE_NAME);

    const models = window.taipbSettings?.models || [];

    // Don't render if there's nothing to choose
    if (models.length <= 1) {
        return null;
    }

    // Group by provider
    const providers = {};
    models.forEach((m) => {
        const p = m.provider || 'other';
        if (!providers[p]) {
            providers[p] = [];
        }
        providers[p].push(m);
    });

    const providerLabels = { anthropic: 'Anthropic', openai: 'OpenAI' };
    const hasMultipleProviders = Object.keys(providers).length > 1;

    // Build options with provider grouping
    const options = [];
    Object.entries(providers).forEach(([provider, providerModels]) => {
        if (hasMultipleProviders) {
            options.push({
                label: `--- ${providerLabels[provider] || provider} ---`,
                value: `__group_${provider}`,
                disabled: true,
            });
        }
        providerModels.forEach((m) => {
            options.push({ label: m.label, value: m.value });
        });
    });

    return (
        <SelectControl
            label="Model"
            value={model}
            options={options}
            onChange={setModel}
            className="taipb-model-selector"
        />
    );
}
