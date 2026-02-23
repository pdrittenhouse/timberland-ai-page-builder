import { PluginDocumentSettingPanel } from '@wordpress/editor';
import { useSelect } from '@wordpress/data';
import { Component } from '@wordpress/element';
import UsageNotesField from './UsageNotesField';

class ErrorBoundary extends Component {
    constructor( props ) {
        super( props );
        this.state = { hasError: false };
    }
    static getDerivedStateFromError() {
        return { hasError: true };
    }
    render() {
        return this.state.hasError ? null : this.props.children;
    }
}

function UsageNotesPanelInner() {
    const postType = useSelect(
        ( select ) => {
            try {
                return select( 'core/editor' )?.getCurrentPostType?.() || null;
            } catch {
                return null;
            }
        },
        []
    );

    const postId = useSelect(
        ( select ) => {
            try {
                return select( 'core/editor' )?.getCurrentPostId?.() || null;
            } catch {
                return null;
            }
        },
        []
    );

    if ( postType !== 'wp_block' || ! postId ) {
        return null;
    }

    return (
        <PluginDocumentSettingPanel
            name="taipb-usage-notes"
            title="AI Usage Notes"
        >
            <UsageNotesField postId={ postId } />
        </PluginDocumentSettingPanel>
    );
}

export default function UsageNotesPanel() {
    return (
        <ErrorBoundary>
            <UsageNotesPanelInner />
        </ErrorBoundary>
    );
}
