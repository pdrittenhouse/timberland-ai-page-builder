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

/**
 * Human-readable guidance per failure code.
 *
 * The codes exist because the right action differs, so the UI has to differ too. A 429 that reads
 * as "something went wrong" sends somebody to regenerate a token that was working perfectly --
 * measured, since five inspections of one file inside a minute is enough to hit Figma's limit.
 */
const ERROR_HELP = {
    figma_rate_limited: 'Figma is rate-limiting this token. Wait about a minute and read it again.',
    figma_forbidden:
        'Figma refused the request. The token may have expired, or it may not have access to this file.',
    taipb_figma_no_token: 'No Figma token is configured. Add one under Settings → AI Page Builder.',
    figma_url_host: 'That is not a figma.com link.',
    figma_url_no_node:
        'That link does not name a frame. In Figma, right-click the frame and choose "Copy link to selection".',
    figma_node_not_found:
        'Figma returned no node for that frame. Check the link points at a frame you can open.',
};

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
                    {ERROR_HELP[error.code] || error.message}
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
                    <p className="taipb-figma-import__summary">
                        {candidates.length} candidate{candidates.length === 1 ? '' : 's'}
                        {meta?.skipped ? `, ${meta.skipped} smaller item(s) skipped` : ''}
                        {/* Truncation is surfaced, never hidden: an operator who cannot find
                            their page needs to know the list is partial, otherwise they conclude
                            the design is unreadable. */}
                        {meta?.truncated
                            ? `. ${meta.truncated} further candidate(s) are not shown — link the page frame directly if yours is missing.`
                            : '.'}
                    </p>

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
                                    Review this page
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
