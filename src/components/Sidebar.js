import { PanelBody } from '@wordpress/components';
import { useSelect } from '@wordpress/data';
import ModelSelector from './ModelSelector';
import GenerationModeToggle from './GenerationModeToggle';
import PromptInput from './PromptInput';
import LayoutPlanPanel from './LayoutPlanPanel';
import StructureReviewPanel from './StructureReviewPanel';
import PatternMatch from './PatternMatch';
import ClarificationPanel from './ClarificationPanel';
import PreviewPanel from './PreviewPanel';
import InsertButton from './InsertButton';
import HistoryPanel from './HistoryPanel';

export default function Sidebar() {
    const postType = useSelect(
        (select) => select('core/editor')?.getCurrentPostType?.() || 'page',
        []
    );

    const postId = useSelect(
        (select) => select('core/editor')?.getCurrentPostId?.() || null,
        []
    );

    return (
        <>
            <PanelBody title="Generate Layout" initialOpen={true}>
                <ModelSelector />
                <GenerationModeToggle />
                <PromptInput postType={postType} postId={postId} />
                <LayoutPlanPanel postType={postType} postId={postId} />
                <StructureReviewPanel postType={postType} postId={postId} />
                <PatternMatch postType={postType} postId={postId} />
                <ClarificationPanel postType={postType} postId={postId} />
                <PreviewPanel />
                <InsertButton />
            </PanelBody>

            <PanelBody title="History" initialOpen={false}>
                <HistoryPanel postId={postId} />
            </PanelBody>
        </>
    );
}
