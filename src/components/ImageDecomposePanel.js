import { useSelect, useDispatch } from '@wordpress/data';
import { Button, Notice, PanelBody } from '@wordpress/components';
import { MediaUpload, MediaUploadCheck } from '@wordpress/block-editor';
import { useState } from '@wordpress/element';

const STORE_NAME = 'timberland-ai-page-builder';

/** What the provider will accept, and what the server independently re-checks. */
const ALLOWED = ['image/png', 'image/jpeg', 'image/gif', 'image/webp'];

/**
 * FG1's reduced-fidelity input: build from an uploaded design when there is no Figma file.
 *
 * **The label is not decoration.** A Figma URL yields frame names, measured reading order,
 * proportions and node-level provenance; an image yields a vision model's impression of all four.
 * The results are worse, and an operator who is not told will attribute the difference to the
 * model and go tuning prompts. So this panel says "reduced fidelity" before the picker, the
 * payload carries `fidelity: reduced`, and the notice survives into the review step.
 *
 * It dispatches `setDecomposition` exactly as the Figma path does, so the same review panel takes
 * over -- a screenshot is a worse input to the same pipeline, not a second pipeline.
 */
export default function ImageDecomposePanel() {
    const { decomposeImage } = useDispatch(STORE_NAME);
    const [rejected, setRejected] = useState('');

    const isBusy = useSelect((select) => select(STORE_NAME).isInspectingFigma(), []);

    const onSelect = (media) => {
        setRejected('');

        if (!media?.id) {
            return;
        }

        // Checked here for an immediate answer, and again on the server against the STORED mime
        // type -- this reads what the library reports, which a crafted upload can disagree with.
        if (media.mime && !ALLOWED.includes(media.mime)) {
            setRejected(
                `${media.mime} cannot be read as a design. Use PNG, JPEG, GIF or WebP.`
            );

            return;
        }

        decomposeImage(media.id, media.filename || media.title);
    };

    return (
        <PanelBody title="Build from an image" initialOpen={false}>
            <Notice status="warning" isDismissible={false}>
                <strong>Reduced fidelity.</strong> A Figma URL gives frame names, reading order,
                proportions and design provenance. An image gives a reading of the picture — no
                provenance, and sections can be missed or merged. Use a full-page export rather
                than a crop.
            </Notice>

            {rejected && (
                <Notice status="error" onRemove={() => setRejected('')}>
                    {rejected}
                </Notice>
            )}

            <MediaUploadCheck
                fallback={
                    <p>
                        You do not have permission to upload files. Someone else can add the design
                        to the media library and you can select it here.
                    </p>
                }
            >
                <MediaUpload
                    allowedTypes={ALLOWED}
                    onSelect={onSelect}
                    render={({ open }) => (
                        <Button variant="secondary" onClick={open} disabled={isBusy}>
                            {isBusy ? 'Reading the design…' : 'Select or upload a design'}
                        </Button>
                    )}
                />
            </MediaUploadCheck>
        </PanelBody>
    );
}
