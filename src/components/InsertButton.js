import { Button, ButtonGroup, Notice } from '@wordpress/components';
import { useSelect, useDispatch } from '@wordpress/data';
import { useState } from '@wordpress/element';
import { STORE_NAME } from '../store';
import { insertMarkup } from '../utils/blockParser';

export default function InsertButton() {
    const [insertResult, setInsertResult] = useState(null);

    const markup = useSelect((select) => select(STORE_NAME).getGeneratedMarkup(), []);
    const { clearResult } = useDispatch(STORE_NAME);

    if (!markup) {
        return null;
    }

    const handleInsert = (mode) => {
        const result = insertMarkup(markup, mode);
        setInsertResult(result);

        if (result.success) {
            // Clear after successful insert
            setTimeout(() => {
                clearResult();
                setInsertResult(null);
            }, 2000);
        }
    };

    return (
        <div className="taipb-insert-buttons">
            <ButtonGroup>
                <Button variant="primary" onClick={() => handleInsert('append')}>
                    Append to page
                </Button>
                <Button variant="secondary" onClick={() => handleInsert('insert')}>
                    Insert at cursor
                </Button>
                <Button variant="tertiary" onClick={() => handleInsert('replace')} isDestructive>
                    Replace all
                </Button>
            </ButtonGroup>

            {insertResult && (
                <Notice
                    status={insertResult.success ? 'success' : 'error'}
                    isDismissible={false}
                    className="taipb-insert-notice"
                >
                    {insertResult.success
                        ? `Inserted ${insertResult.blockCount} block(s)`
                        : insertResult.error}
                </Notice>
            )}
        </div>
    );
}
