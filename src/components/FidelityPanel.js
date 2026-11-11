import { Notice } from '@wordpress/components';
import { useSelect } from '@wordpress/data';
import { STORE_NAME } from '../store';

/**
 * How much of the confirmed plan reached the page.
 *
 * **Shown beside validation, never merged into it.** They answer different questions and a page can
 * pass one while failing the other badly: the generation that prompted this reported
 * `0 validation errors` and had no imagery at all. Folding them into one "quality" indicator would
 * hide exactly that.
 *
 * Reads as a report rather than a score. There is no single number on purpose — one number invites
 * tuning the number, and each of these signals fails differently and is checkable by hand against
 * the design.
 */
export default function FidelityPanel() {
    const report = useSelect(
        (select) => select(STORE_NAME).getFidelityReport(),
        []
    );

    // Absent on the direct path, which never had a confirmed plan to compare against. An empty
    // panel would imply the check ran and found nothing.
    if (!report) {
        return null;
    }

    const { sections, text, images, order, notes } = report;
    const coverage = Math.round((text?.coverage ?? 0) * 100);

    return (
        <div className="taipb-fidelity">
            <h3 className="taipb-fidelity__heading">How much of the plan arrived</h3>

            <ul className="taipb-fidelity__signals">
                <li>
                    <strong>Sections</strong> {sections.produced} of {sections.planned}
                    {!sections.covered && ' — some produced no block'}
                </li>
                <li>
                    <strong>Copy</strong> {text.found} of {text.planned} strings ({coverage}%)
                </li>
                <li>
                    <strong>Images</strong> {images.produced} set, {images.planned} described
                </li>
                <li>
                    <strong>Order</strong>{' '}
                    {order.preserved ? 'as planned' : 'differs from the plan'}
                    {/* How many titles the verdict rests on, so "as planned" is not read as
                        "every section was checked" — short titles are excluded because they match
                        inside unrelated copy. */}
                    {typeof order.checked === 'number' &&
                        ` (${order.checked} title${order.checked === 1 ? '' : 's'} checked)`}
                </li>
            </ul>

            {notes?.length > 0 && (
                <Notice status="warning" isDismissible={false}>
                    <ul className="taipb-fidelity__notes">
                        {notes.map((note) => (
                            <li key={note}>{note}</li>
                        ))}
                    </ul>
                </Notice>
            )}

            {text?.missing?.length > 0 && (
                <details className="taipb-fidelity__missing">
                    <summary>Copy that did not reach the page ({text.missing.length})</summary>
                    {/* Named, not just counted: "82%" is not actionable, three named strings are. */}
                    <ul>
                        {text.missing.map((line) => (
                            <li key={line}>{line}</li>
                        ))}
                    </ul>
                </details>
            )}
        </div>
    );
}
