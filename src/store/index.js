import { createReduxStore, register } from '@wordpress/data';
import apiFetch from '@wordpress/api-fetch';

const STORE_NAME = 'timberland-ai-page-builder';

const DEFAULT_STATE = {
    prompt: '',
    isGenerating: false,
    isMatching: false,
    isAnalyzing: false,
    patternMatches: [],
    selectedPattern: null,
    clarificationQuestions: [],
    clarificationAnswers: {},
    generatedMarkup: '',
    validationResult: null,
    apiResponse: null,
    error: null,
    history: [],
    isLoadingHistory: false,
};

const actions = {
    setPrompt(prompt) {
        return { type: 'SET_PROMPT', prompt };
    },

    setGenerating(isGenerating) {
        return { type: 'SET_GENERATING', isGenerating };
    },

    setMatching(isMatching) {
        return { type: 'SET_MATCHING', isMatching };
    },

    setAnalyzing(isAnalyzing) {
        return { type: 'SET_ANALYZING', isAnalyzing };
    },

    setPatternMatches(matches) {
        return { type: 'SET_PATTERN_MATCHES', matches };
    },

    selectPattern(patternId) {
        return { type: 'SELECT_PATTERN', patternId };
    },

    clearMatches() {
        return { type: 'CLEAR_MATCHES' };
    },

    setClarificationQuestions(questions) {
        return { type: 'SET_CLARIFICATION_QUESTIONS', questions };
    },

    setClarificationAnswer(questionId, value) {
        return { type: 'SET_CLARIFICATION_ANSWER', questionId, value };
    },

    clearClarification() {
        return { type: 'CLEAR_CLARIFICATION' };
    },

    setResult(markup, validation, apiResponse) {
        return {
            type: 'SET_RESULT',
            markup,
            validation,
            apiResponse,
        };
    },

    setError(error) {
        return { type: 'SET_ERROR', error };
    },

    clearResult() {
        return { type: 'CLEAR_RESULT' };
    },

    setHistory(history) {
        return { type: 'SET_HISTORY', history };
    },

    setLoadingHistory(isLoading) {
        return { type: 'SET_LOADING_HISTORY', isLoading };
    },

    /**
     * Step 1: Check for matching patterns/layouts before generating.
     * Auto-selects when there's exactly one match or one dominant match.
     */
    checkMatches(prompt, postType, postId) {
        return async ({ dispatch }) => {
            dispatch.setMatching(true);
            dispatch.setError(null);
            dispatch.clearMatches();
            dispatch.clearClarification();
            dispatch.clearResult();

            try {
                const result = await apiFetch({
                    path: '/taipb/v1/match',
                    method: 'POST',
                    data: { prompt },
                });

                if (result.has_matches && result.matches.length > 0) {
                    const matches = result.matches;

                    // Auto-select if there's exactly one match, or one
                    // dominant match (score >= 8 and 1.5x the runner-up)
                    const autoSelect =
                        matches.length === 1 ||
                        (matches[0].score >= 8 &&
                            (matches.length < 2 ||
                                matches[0].score >=
                                    matches[1].score * 1.5));

                    if (autoSelect) {
                        dispatch.setMatching(false);
                        dispatch.analyzePattern(
                            prompt,
                            postType,
                            postId,
                            matches[0].id
                        );
                        return;
                    }

                    dispatch.setPatternMatches(matches);
                } else {
                    dispatch.generateWithPattern(prompt, postType, postId, null);
                }
            } catch {
                dispatch.generateWithPattern(prompt, postType, postId, null);
            } finally {
                dispatch.setMatching(false);
            }
        };
    },

    /**
     * Step 2: Analyze pattern + prompt for ambiguities.
     * If questions exist, show them. Otherwise, proceed to generation.
     */
    analyzePattern(prompt, postType, postId, usePattern) {
        return async ({ dispatch }) => {
            if (!usePattern) {
                dispatch.generateWithPattern(prompt, postType, postId, null);
                return;
            }

            dispatch.setAnalyzing(true);
            dispatch.setError(null);
            dispatch.selectPattern(usePattern);

            try {
                const result = await apiFetch({
                    path: '/taipb/v1/analyze',
                    method: 'POST',
                    data: { prompt, use_pattern: usePattern },
                });

                if (result.has_questions) {
                    dispatch.setClarificationQuestions(result.questions);
                    // Set defaults
                    for (const q of result.questions) {
                        if (q.default) {
                            dispatch.setClarificationAnswer(q.id, q.default);
                        }
                    }
                } else {
                    dispatch.generateWithPattern(
                        prompt,
                        postType,
                        postId,
                        usePattern
                    );
                }
            } catch {
                // Analysis failed — proceed without clarification
                dispatch.generateWithPattern(
                    prompt,
                    postType,
                    postId,
                    usePattern
                );
            } finally {
                dispatch.setAnalyzing(false);
            }
        };
    },

    /**
     * Step 3: Generate with optional pattern selection and clarification answers.
     */
    generateWithPattern(prompt, postType, postId, usePattern, answers) {
        return async ({ dispatch }) => {
            dispatch.setGenerating(true);
            dispatch.setError(null);
            dispatch.clearMatches();

            // Append clarification context to the prompt if answers provided
            let finalPrompt = prompt;
            if (answers && Object.keys(answers).length > 0) {
                const clarifications = [];
                for (const [key, value] of Object.entries(answers)) {
                    if (key === 'hero_content_location') {
                        if (value === 'data_fields') {
                            clarifications.push(
                                'Apply title/text changes to the block data fields (title, subtitle, text), NOT to InnerBlocks.'
                            );
                        } else if (value === 'innerblocks') {
                            clarifications.push(
                                'Apply title/text changes to the InnerBlocks (wp:heading, wp:paragraph), NOT to data fields.'
                            );
                        } else if (value === 'both') {
                            clarifications.push(
                                'Apply title/text changes to BOTH the block data fields AND the InnerBlocks.'
                            );
                        }
                    } else if (key === 'image_handling') {
                        if (value === 'keep') {
                            clarifications.push(
                                'Keep the existing pattern image unchanged.'
                            );
                        } else if (value === 'clear') {
                            clarifications.push(
                                'Remove the image so I can set it manually.'
                            );
                        } else if (value === 'provide_name') {
                            const filename =
                                answers['image_handling_value'] || '';
                            if (filename) {
                                clarifications.push(
                                    `Use the image named "${filename}" from the media library for the image field.`
                                );
                            }
                        } else if (value === 'provide_url') {
                            const url =
                                answers['image_handling_value'] || '';
                            if (url) {
                                clarifications.push(
                                    `Use this image URL for the image field: ${url}`
                                );
                            }
                        }
                    }
                }
                if (clarifications.length > 0) {
                    finalPrompt +=
                        '\n\n[User clarifications: ' +
                        clarifications.join(' ') +
                        ']';
                }
            }

            try {
                const data = {
                    prompt: finalPrompt,
                    post_type: postType || 'page',
                    post_id: postId || null,
                };
                if (usePattern) {
                    data.use_pattern = usePattern;
                }

                const result = await apiFetch({
                    path: '/taipb/v1/generate',
                    method: 'POST',
                    data,
                });

                if (result.error) {
                    dispatch.setError(result.error);
                } else {
                    dispatch.setResult(
                        result.markup,
                        result.validation,
                        result.api_response
                    );
                }
            } catch (err) {
                const message =
                    err?.message ||
                    err?.error ||
                    err?.data?.message ||
                    (typeof err === 'string'
                        ? err
                        : 'Generation failed. Please try again.');
                dispatch.setError(message);
            } finally {
                dispatch.setGenerating(false);
                dispatch.clearClarification();
            }
        };
    },

    fetchHistory(postId) {
        return async ({ dispatch }) => {
            dispatch.setLoadingHistory(true);

            try {
                const params = new URLSearchParams({ per_page: '10' });
                if (postId) {
                    params.set('post_id', postId);
                }

                const result = await apiFetch({
                    path: `/taipb/v1/history?${params.toString()}`,
                });

                dispatch.setHistory(result.items || []);
            } catch {
                // Silently fail for history
            } finally {
                dispatch.setLoadingHistory(false);
            }
        };
    },
};

const reducer = (state = DEFAULT_STATE, action) => {
    switch (action.type) {
        case 'SET_PROMPT':
            return { ...state, prompt: action.prompt };

        case 'SET_GENERATING':
            return { ...state, isGenerating: action.isGenerating };

        case 'SET_MATCHING':
            return { ...state, isMatching: action.isMatching };

        case 'SET_ANALYZING':
            return { ...state, isAnalyzing: action.isAnalyzing };

        case 'SET_PATTERN_MATCHES':
            return { ...state, patternMatches: action.matches };

        case 'SELECT_PATTERN':
            return { ...state, selectedPattern: action.patternId };

        case 'CLEAR_MATCHES':
            return { ...state, patternMatches: [], selectedPattern: null };

        case 'SET_CLARIFICATION_QUESTIONS':
            return { ...state, clarificationQuestions: action.questions };

        case 'SET_CLARIFICATION_ANSWER':
            return {
                ...state,
                clarificationAnswers: {
                    ...state.clarificationAnswers,
                    [action.questionId]: action.value,
                },
            };

        case 'CLEAR_CLARIFICATION':
            return {
                ...state,
                clarificationQuestions: [],
                clarificationAnswers: {},
            };

        case 'SET_RESULT':
            return {
                ...state,
                generatedMarkup: action.markup,
                validationResult: action.validation,
                apiResponse: action.apiResponse,
                error: null,
            };

        case 'SET_ERROR':
            return { ...state, error: action.error };

        case 'CLEAR_RESULT':
            return {
                ...state,
                generatedMarkup: '',
                validationResult: null,
                apiResponse: null,
                error: null,
            };

        case 'SET_HISTORY':
            return { ...state, history: action.history };

        case 'SET_LOADING_HISTORY':
            return { ...state, isLoadingHistory: action.isLoading };

        default:
            return state;
    }
};

const selectors = {
    getPrompt: (state) => state.prompt,
    isGenerating: (state) => state.isGenerating,
    isMatching: (state) => state.isMatching,
    isAnalyzing: (state) => state.isAnalyzing,
    getPatternMatches: (state) => state.patternMatches,
    getSelectedPattern: (state) => state.selectedPattern,
    getClarificationQuestions: (state) => state.clarificationQuestions,
    getClarificationAnswers: (state) => state.clarificationAnswers,
    getGeneratedMarkup: (state) => state.generatedMarkup,
    getValidationResult: (state) => state.validationResult,
    getApiResponse: (state) => state.apiResponse,
    getError: (state) => state.error,
    getHistory: (state) => state.history,
    isLoadingHistory: (state) => state.isLoadingHistory,
};

const store = createReduxStore(STORE_NAME, {
    reducer,
    actions,
    selectors,
});

register(store);

export default store;
export { STORE_NAME };
