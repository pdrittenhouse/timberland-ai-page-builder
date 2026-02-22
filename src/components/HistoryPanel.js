import { Button, Spinner } from '@wordpress/components';
import { useSelect, useDispatch } from '@wordpress/data';
import { useEffect } from '@wordpress/element';
import { STORE_NAME } from '../store';

export default function HistoryPanel({ postId }) {
    const { history, isLoading } = useSelect(
        (select) => ({
            history: select(STORE_NAME).getHistory(),
            isLoading: select(STORE_NAME).isLoadingHistory(),
        }),
        []
    );

    const { fetchHistory, setPrompt } = useDispatch(STORE_NAME);

    useEffect(() => {
        fetchHistory(postId);
    }, [postId]);

    if (isLoading) {
        return (
            <div className="taipb-history-loading">
                <Spinner />
            </div>
        );
    }

    if (!history || history.length === 0) {
        return <p className="taipb-history-empty">No generation history yet.</p>;
    }

    return (
        <div className="taipb-history-panel">
            {history.map((item) => (
                <div key={item.id} className="taipb-history-item">
                    <div className="taipb-history-prompt">{item.prompt}</div>
                    <div className="taipb-history-meta">
                        {item.model} &middot; {item.input_tokens + item.output_tokens} tokens
                        &middot; {new Date(item.created_at).toLocaleDateString()}
                    </div>
                    <Button
                        variant="link"
                        isSmall
                        onClick={() => setPrompt(item.prompt)}
                    >
                        Reuse prompt
                    </Button>
                </div>
            ))}
        </div>
    );
}
