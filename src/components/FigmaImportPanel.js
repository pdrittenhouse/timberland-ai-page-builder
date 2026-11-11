import {
    Button,
    Card,
    CardBody,
    Notice,
    Spinner,
    TextControl,
} from '@wordpress/components';
import { useSelect, useDispatch } from '@wordpress/data';
import { STORE_NAME } from '../store';

/*
 * There is deliberately NO local map of per-code help text here.
 *
 * There was one, and it shadowed the server's message for every code it covered. Within hours it
 * was actively lying: the server had learned to read Figma's real `retry-after` (a measured 4.6
 * days) while this file still said "wait about a minute and read it again" -- the precise wrong
 * advice, on the one failure where retrying is expensive. Two copies of the same guidance, and the
 * stale one won because it was checked first.
 *
 * The server messages are already written for the person who pasted the URL -- FigmaUrl says so in
 * its own docblock -- and only the server can know the numbers. So the panel renders what the API
 * returned and keeps one source of truth.
 */

/**
 * Step 1 of the Figma path: read a link, show what is in it, let the operator choose.
 *
 * **It ends by handing a plan to the ordinary review panel.** Choosing a candidate dispatches
 * `setDecomposition`, which is exactly what a typed prompt does, so `LayoutPlanPanel` takes over
 * and the operator edits and confirms the plan the same way. There is deliberately no
 * Figma-specific confirm step: a second review path is a path where nobody looks, and this is the
 * path where looking matters most, because the operator can see the file and the model cannot.
 *
 * **Candidates are listed, not filtered down to a guess.** A file nobody tidied for us contains
 * page frames, per-content-type variants and loose design assets, and no amount of geometry
 * separates a 683x582 design from a 683x582 mood board. So each row shows its own evidence --
 * name, size, how many sections it would yield and the titles of the first few -- and the choice
 * stays with the person who can recognise their own file.
 */
export default function FigmaImportPanel() {
    const { url, candidates, meta, isInspecting, error, decomposition, blockTree } = useSelect(
        (select) => ({
            url: select(STORE_NAME).getFigmaUrl(),
            candidates: select(STORE_NAME).getFigmaCandidates(),
            meta: select(STORE_NAME).getFigmaMeta(),
            isInspecting: select(STORE_NAME).isInspectingFigma(),
            error: select(STORE_NAME).getFigmaError(),
            decomposition: select(STORE_NAME).getDecomposition(),
            blockTree: select(STORE_NAME).getBlockTree(),
        }),
        []
    );

    const { setFigmaUrl, inspectFigma, applyFigmaCandidate } = useDispatch(STORE_NAME);

    // Once a plan is under review the candidate list is noise -- LayoutPlanPanel owns the screen.
    if (decomposition || blockTree) {
        return null;
    }

    const trimmed = (url || '').trim();
    const depth = meta?.depth ?? 0;

    return (
        <div className="taipb-figma-import">
            <TextControl
                label="Figma frame link"
                help='Right-click the page frame in Figma and choose "Copy link to selection".'
                value={url || ''}
                onChange={setFigmaUrl}
                disabled={isInspecting}
                __nextHasNoMarginBottom
            />

            <Button
                variant="secondary"
                onClick={() => inspectFigma(trimmed, 0)}
                disabled={isInspecting || trimmed === ''}
            >
                {isInspecting ? 'Reading design…' : 'Read design'}
            </Button>

            {isInspecting && <Spinner />}

            {error && (
                <Notice status="error" isDismissible={false}>
                    {error.message || 'Could not read that Figma file.'}
                </Notice>
            )}

            {candidates && candidates.length === 0 && (
                <Notice status="warning" isDismissible={false}>
                    Nothing in that frame is large enough to be a page design
                    {meta?.skipped ? ` (${meta.skipped} smaller items skipped)` : ''}. Try linking a
                    specific page frame rather than the whole canvas.
                </Notice>
            )}

            {candidates && candidates.length > 0 && (
                <>
                    {/* Said before the click, not after: choosing a page downloads that page's
                        images into the media library, and an operator should know that is what the
                        button does. */}
                    <p className="taipb-figma-import__summary">
                        {candidates.length} candidate{candidates.length === 1 ? '' : 's'}
                        {meta?.skipped ? `, ${meta.skipped} smaller item(s) skipped` : ''}
                        {/* Truncation is surfaced, never hidden: an operator who cannot find
                            their page needs to know the list is partial, otherwise they conclude
                            the design is unreadable. */}
                        {meta?.truncated
                            ? `. ${meta.truncated} further candidate(s) are not shown — link the page frame directly if yours is missing.`
                            : '.'}
                        {' '}
                        Choosing one imports that page&rsquo;s images into your media library, so the
                        generated page can actually use them.
                    </p>

                    {/* Stated plainly, because an operator whose design has changed in Figma needs
                        to tell "this reading is up to an hour old" from "the extractor misread my
                        file". Re-reading is a real cost: Figma's limit here is cost-based and one
                        measured lockout carried retry-after: 4.6 days, so this is offered rather
                        than done automatically. */}
                    {meta?.cached && (
                        <Notice status="info" isDismissible={false}>
                            Showing a saved reading of this frame.{' '}
                            <Button
                                variant="link"
                                onClick={() => inspectFigma(trimmed, depth, true)}
                                disabled={isInspecting}
                            >
                                Read it again from Figma
                            </Button>{' '}
                            — only if the design has changed, as Figma limits how often a large file
                            can be read.
                        </Notice>
                    )}

                    {candidates.map((candidate) => (
                        <Card key={candidate.id} size="small" className="taipb-figma-candidate">
                            <CardBody>
                                <strong>{candidate.name || 'Unnamed frame'}</strong>
                                <div className="taipb-figma-candidate__meta">
                                    {candidate.width}×{candidate.height}
                                    {' · '}
                                    {candidate.decomposition.sections.length} section
                                    {candidate.decomposition.sections.length === 1 ? '' : 's'}
                                    {candidate.parent ? ` · inside "${candidate.parent}"` : ''}
                                </div>

                                <ol className="taipb-figma-candidate__sections">
                                    {candidate.decomposition.sections.slice(0, 4).map((section) => (
                                        <li key={section.figma?.node || section.intent}>
                                            {section.content?.title ||
                                                section.content?.subtitle ||
                                                'no text — likely an image band'}
                                        </li>
                                    ))}
                                </ol>

                                <Button
                                    variant="primary"
                                    /* The list is NOT cleared on pick. The panel hides itself
                                       while a plan is under review, so an operator who backs out
                                       of the wrong page gets the candidates back without a second
                                       fetch -- which is the whole point of fetching once, and
                                       matters more than it sounds given Figma's rate limit. */
                                    onClick={() => applyFigmaCandidate(candidate)}
                                    disabled={candidate.decomposition.sections.length === 0}
                                >
                                    {isInspecting ? 'Importing images…' : 'Review this page'}
                                </Button>
                            </CardBody>
                        </Card>
                    ))}

                    {/* Nested frames are opt-in. Measured on a real file, descending gave MORE
                        rows and FEWER names, and dropped 33 candidates the top level showed -- so
                        it is the wrong default but the right escape hatch when a file wraps its
                        pages in a group. */}
                    {depth === 0 && (
                        <Button
                            variant="link"
                            onClick={() => inspectFigma(trimmed, 1)}
                            disabled={isInspecting}
                        >
                            My page is not listed — look inside these frames
                        </Button>
                    )}
                </>
            )}
        </div>
    );
}
