import { PluginDocumentSettingPanel } from '@wordpress/editor';
import { useSelect } from '@wordpress/data';
import { Component, useEffect, useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';

/**
 * VP4 — what the post-publish checks said about this page.
 *
 * The checks need a real browser, which this plugin never launches: it lists the pages that owe
 * one and accepts the results, and the suite runs them. So this panel reports a state it does not
 * control, and the states it must keep apart are the ones that look identical as data:
 *
 *  - not generated        -- this plugin never touched the page; say nothing.
 *  - not expected         -- nobody runs the suite for this site. No report will ever arrive, so
 *                            calling it "pending" would promise something that never comes. This
 *                            is what the "Expect post-publish checks" setting distinguishes.
 *  - pending              -- a check is genuinely owed (never run, or the page changed since).
 *  - reported             -- which may be clean, failing, or CLEAN BUT VACUOUS. A spec that
 *                            generates zero tests passes, so `covered_nothing` is surfaced on its
 *                            own rather than folded into the verdict; without it, a run that
 *                            checked nothing is indistinguishable from one that passed.
 */
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

function CheckReportPanelInner() {
    const postId = useSelect( ( select ) => {
        try {
            return select( 'core/editor' )?.getCurrentPostId?.() || null;
        } catch {
            return null;
        }
    }, [] );

    // Re-read when the page is saved: a save moves `post_modified_gmt`, which is exactly what
    // makes a previously-checked page pending again.
    const isSaving = useSelect( ( select ) => {
        try {
            return !! select( 'core/editor' )?.isSavingPost?.();
        } catch {
            return false;
        }
    }, [] );

    const [ status, setStatus ] = useState( null );
    const [ error, setError ] = useState( null );

    useEffect( () => {
        if ( ! postId || isSaving ) {
            return;
        }

        let cancelled = false;

        apiFetch( { path: `/taipb/v1/checks/status?post_id=${ postId }` } )
            .then( ( data ) => {
                if ( ! cancelled ) {
                    setStatus( data );
                    setError( null );
                }
            } )
            .catch( ( err ) => {
                // The server's own message, not a local map of it. A panel that substituted its
                // own wording for the server's has already hidden one real error in this plugin.
                if ( ! cancelled ) {
                    setError( err?.message || 'Could not read the check status.' );
                }
            } );

        return () => {
            cancelled = true;
        };
    }, [ postId, isSaving ] );

    // Nothing to say about a page this plugin never generated.
    if ( ! postId || ( status && ! status.generated ) ) {
        return null;
    }

    const body = () => {
        if ( error ) {
            return <p className="taipb-check-error">{ error }</p>;
        }

        if ( ! status ) {
            return <p>Reading…</p>;
        }

        if ( ! status.expected ) {
            return (
                <p>
                    Post-publish checks are not enabled for this site, so no report will arrive.
                    Turn on <em>Expect post-publish checks</em> in the plugin settings if someone
                    runs <code>gate:generated --queue</code> here.
                </p>
            );
        }

        if ( ! status.published ) {
            return <p>Checks run against a published URL. This page is not published yet.</p>;
        }

        const { report } = status;

        if ( status.pending ) {
            return (
                <p>
                    { report
                        ? 'This page changed since its last check, so a fresh one is owed.'
                        : 'A check is owed. It runs when the suite next pulls the queue.' }
                </p>
            );
        }

        if ( ! report ) {
            return <p>No report.</p>;
        }

        const vacuous = ( report.covered_nothing || [] ).length > 0;

        return (
            <>
                <p>
                    <strong>
                        { vacuous ? 'Checked, but incomplete' : report.ok ? 'Passed' : 'Failed' }
                    </strong>
                    { report.checked_at ? ` — ${ report.checked_at }` : '' }
                </p>

                <ul className="taipb-check-specs">
                    { ( report.specs || [] ).map( ( spec ) => (
                        <li key={ spec.spec }>
                            { spec.failed > 0 ? '✗' : '✓' } { spec.spec }
                            { spec.failed > 0 ? ` — ${ spec.failed } of ${ spec.ran } failed` : '' }
                        </li>
                    ) ) }
                </ul>

                { vacuous && (
                    <p className="taipb-check-vacuous">
                        { report.covered_nothing.join( ', ' ) } covered nothing. A check that
                        generates no tests passes, so this is not a clean result.
                    </p>
                ) }
            </>
        );
    };

    return (
        <PluginDocumentSettingPanel name="taipb-check-report" title="Post-Publish Checks">
            { body() }
        </PluginDocumentSettingPanel>
    );
}

export default function CheckReportPanel() {
    return (
        <ErrorBoundary>
            <CheckReportPanelInner />
        </ErrorBoundary>
    );
}
