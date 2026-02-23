import { TextareaControl, Button, Flex, Notice } from '@wordpress/components';
import { useState, useEffect } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';

export default function UsageNotesField( { postId } ) {
    const [ value, setValue ] = useState( '' );
    const [ saved, setSaved ] = useState( true );
    const [ saving, setSaving ] = useState( false );
    const [ generating, setGenerating ] = useState( false );
    const [ error, setError ] = useState( '' );

    useEffect( () => {
        if ( ! postId ) return;
        apiFetch( { path: `/wp/v2/blocks/${ postId }` } )
            .then( ( post ) => setValue( post.meta?.taipb_usage_notes || '' ) )
            .catch( () => {} );
    }, [ postId ] );

    const handleSave = () => {
        setSaving( true );
        setError( '' );
        apiFetch( {
            path: `/wp/v2/blocks/${ postId }`,
            method: 'POST',
            data: { meta: { taipb_usage_notes: value } },
        } )
            .then( () => {
                setSaving( false );
                setSaved( true );
            } )
            .catch( ( err ) => {
                setSaving( false );
                setError( err.message || 'Failed to save.' );
            } );
    };

    const handleGenerate = () => {
        setGenerating( true );
        setError( '' );
        apiFetch( {
            path: '/taipb/v1/generate-pattern-notes',
            method: 'POST',
            data: { post_id: postId },
        } )
            .then( ( result ) => {
                setGenerating( false );
                if ( result.notes ) {
                    setValue( result.notes );
                    setSaved( false );
                }
            } )
            .catch( ( err ) => {
                setGenerating( false );
                setError( err.message || 'Failed to generate.' );
            } );
    };

    return (
        <>
            { error && (
                <Notice status="error" isDismissible={ true } onRemove={ () => setError( '' ) }>
                    { error }
                </Notice>
            ) }
            <TextareaControl
                label="Usage Notes"
                help="Describe how this pattern should be used by the AI layout generator."
                value={ value }
                onChange={ ( newValue ) => {
                    setValue( newValue );
                    setSaved( false );
                } }
                rows={ 3 }
            />
            <Flex justify="flex-start" gap={ 2 }>
                <Button
                    variant="secondary"
                    size="small"
                    onClick={ handleSave }
                    disabled={ saving || saved }
                >
                    { saving ? 'Saving...' : saved ? 'Saved' : 'Save Notes' }
                </Button>
                <Button
                    variant="tertiary"
                    size="small"
                    onClick={ handleGenerate }
                    disabled={ generating }
                >
                    { generating ? 'Generating...' : 'Auto-Generate' }
                </Button>
            </Flex>
        </>
    );
}
