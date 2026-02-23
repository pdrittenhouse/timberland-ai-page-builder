import { registerPlugin } from '@wordpress/plugins';
import { PluginSidebar } from '@wordpress/edit-post';

import './store';
import './editor.css';
import Sidebar from './components/Sidebar';
import UsageNotesPanel from './components/UsageNotesPanel';

registerPlugin('timberland-ai-page-builder', {
    icon: 'layout',
    render: () => (
        <PluginSidebar
            name="timberland-ai-page-builder"
            title="AI Page Builder"
            icon="layout"
        >
            <Sidebar />
        </PluginSidebar>
    ),
});

registerPlugin('taipb-pattern-usage-notes', {
    render: UsageNotesPanel,
});
