import { createReduxStore, register } from '@wordpress/data';
import apiFetch from '@wordpress/api-fetch';

const STORE_NAME = 'timberland-ai-page-builder';

const DEFAULT_STATE = {
    prompt: '',
    selectedModel: window.taipbSettings?.defaultModel || '',
    isGenerating: false,
    isMatching: false,
    isAnalyzing: false,
    patternMatches: [],
    selectedPatterns: [],
    clarificationQuestions: [],
    clarificationAnswers: {},
    decomposition: null,
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

    setModel(model) {
        return { type: 'SET_MODEL', model };
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

    selectPatterns(patternIds) {
        return { type: 'SELECT_PATTERNS', patternIds };
    },

    togglePattern(patternId) {
        return { type: 'TOGGLE_PATTERN', patternId };
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

    setDecomposition(decomposition) {
        return { type: 'SET_DECOMPOSITION', decomposition };
    },

    clearDecomposition() {
        return { type: 'CLEAR_DECOMPOSITION' };
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
     * Step 1: Try LLM decomposition first, fall back to keyword matching.
     * If decomposition returns sections, store them and let LayoutPlanPanel render.
     * Otherwise, fall through to keyword-based matching.
     */
    checkMatches(prompt, postType, postId) {
        return async ({ dispatch }) => {
            dispatch.setMatching(true);
            dispatch.setError(null);
            dispatch.clearMatches();
            dispatch.clearClarification();
            dispatch.clearResult();
            dispatch.clearDecomposition();

            try {
                // Try LLM-based decomposition first
                try {
                    const decomp = await apiFetch({
                        path: '/taipb/v1/decompose',
                        method: 'POST',
                        data: { prompt },
                    });

                    if (decomp.has_sections && decomp.decomposition?.sections?.length > 0) {
                        // Decomposition succeeded — show layout plan for user review
                        dispatch.setDecomposition(decomp.decomposition);
                        dispatch.setMatching(false);
                        return;
                    }
                } catch {
                    // Decomposition failed — fall through to keyword matching
                }

                // Keyword-based matching fallback
                const result = await apiFetch({
                    path: '/taipb/v1/match',
                    method: 'POST',
                    data: { prompt },
                });

                if (result.has_matches && result.matches.length > 0) {
                    const matches = result.matches;

                    // Collect all high-scoring matches (score >= 8)
                    const strongMatches = matches.filter(
                        (m) => m.score >= 8
                    );

                    // Single dominant match: exactly 1 match, or top score >= 8
                    // and >= 1.5x the runner-up
                    const singleDominant =
                        matches.length === 1 ||
                        (matches[0].score >= 8 &&
                            (matches.length < 2 ||
                                matches[0].score >=
                                    matches[1].score * 1.5));

                    if (singleDominant && strongMatches.length <= 1) {
                        // Auto-select single pattern
                        dispatch.setMatching(false);
                        dispatch.analyzePatterns(prompt, postType, postId, [
                            matches[0].id,
                        ]);
                        return;
                    }

                    if (strongMatches.length >= 2) {
                        // Auto-select all strong matches (multi-pattern)
                        dispatch.setMatching(false);
                        dispatch.analyzePatterns(
                            prompt,
                            postType,
                            postId,
                            strongMatches.map((m) => m.id)
                        );
                        return;
                    }

                    // Show match selection UI
                    dispatch.setPatternMatches(matches);
                } else {
                    dispatch.generateWithPatterns(
                        prompt,
                        postType,
                        postId,
                        []
                    );
                }
            } catch {
                dispatch.generateWithPatterns(prompt, postType, postId, []);
            } finally {
                dispatch.setMatching(false);
            }
        };
    },

    /**
     * Step 2: Analyze patterns + prompt for ambiguities.
     * If questions exist, show them. Otherwise, proceed to generation.
     */
    analyzePatterns(prompt, postType, postId, usePatterns) {
        return async ({ dispatch }) => {
            if (!usePatterns || usePatterns.length === 0) {
                dispatch.generateWithPatterns(prompt, postType, postId, []);
                return;
            }

            dispatch.setAnalyzing(true);
            dispatch.setError(null);
            dispatch.selectPatterns(usePatterns);

            try {
                const result = await apiFetch({
                    path: '/taipb/v1/analyze',
                    method: 'POST',
                    data: { prompt, use_patterns: usePatterns },
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
                    dispatch.generateWithPatterns(
                        prompt,
                        postType,
                        postId,
                        usePatterns
                    );
                }
            } catch {
                // Analysis failed — proceed without clarification
                dispatch.generateWithPatterns(
                    prompt,
                    postType,
                    postId,
                    usePatterns
                );
            } finally {
                dispatch.setAnalyzing(false);
            }
        };
    },

    /**
     * Step 3: Generate with optional pattern selections and clarification answers.
     */
    generateWithPatterns(prompt, postType, postId, usePatterns, answers) {
        return async ({ dispatch, select: storeSelect }) => {
            dispatch.setGenerating(true);
            dispatch.setError(null);
            dispatch.clearMatches();

            // Append clarification context to the prompt if answers provided
            let finalPrompt = prompt;
            if (answers && Object.keys(answers).length > 0) {
                const clarifications = [];
                for (const [key, value] of Object.entries(answers)) {
                    // Strip pattern ID prefix if present (e.g., "pattern_123__hero_content_location")
                    const baseKey = key.includes('__')
                        ? key.split('__').pop()
                        : key;

                    if (baseKey === 'hero_content_location') {
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
                    } else if (baseKey === 'image_handling') {
                        if (value === 'keep') {
                            clarifications.push(
                                'Keep the existing pattern image unchanged.'
                            );
                        } else if (value === 'clear') {
                            clarifications.push(
                                'Remove the image so I can set it manually.'
                            );
                        } else if (value === 'provide_name') {
                            const valueKey = key.includes('__')
                                ? key.replace(
                                      'image_handling',
                                      'image_handling_value'
                                  )
                                : 'image_handling_value';
                            const filename = answers[valueKey] || '';
                            if (filename) {
                                clarifications.push(
                                    `Use the image named "${filename}" from the media library for the image field.`
                                );
                            }
                        } else if (value === 'provide_url') {
                            const valueKey = key.includes('__')
                                ? key.replace(
                                      'image_handling',
                                      'image_handling_value'
                                  )
                                : 'image_handling_value';
                            const url = answers[valueKey] || '';
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

            // Append structured layout plan from decomposition if available
            const decomposition = storeSelect.getDecomposition();
            if (decomposition?.sections?.length > 0) {
                const structuredHint = decomposition.sections
                    .map((s, i) => {
                        let hint = `Section ${i + 1}: ${s.intent}`;
                        if (s.pattern_hint) {
                            hint += ` (use pattern: ${s.pattern_hint})`;
                        }
                        if (s.content) {
                            Object.entries(s.content).forEach(([k, v]) => {
                                if (v) {
                                    hint += `\n  ${k}: ${v}`;
                                }
                            });
                        }
                        return hint;
                    })
                    .join('\n\n');

                finalPrompt +=
                    '\n\n[Structured layout plan:\n' +
                    structuredHint +
                    '\n]';
            }

            // Get selected model
            const model = storeSelect.getModel();

            try {
                const data = {
                    prompt: finalPrompt,
                    post_type: postType || 'page',
                    post_id: postId || null,
                };
                if (usePatterns && usePatterns.length > 0) {
                    data.use_patterns = usePatterns;
                }
                if (model) {
                    data.model = model;
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
                dispatch.clearDecomposition();
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

        case 'SET_MODEL':
            return { ...state, selectedModel: action.model };

        case 'SET_GENERATING':
            return { ...state, isGenerating: action.isGenerating };

        case 'SET_MATCHING':
            return { ...state, isMatching: action.isMatching };

        case 'SET_ANALYZING':
            return { ...state, isAnalyzing: action.isAnalyzing };

        case 'SET_PATTERN_MATCHES':
            return { ...state, patternMatches: action.matches };

        case 'SELECT_PATTERNS':
            return { ...state, selectedPatterns: action.patternIds };

        case 'TOGGLE_PATTERN':
            return {
                ...state,
                selectedPatterns: state.selectedPatterns.includes(
                    action.patternId
                )
                    ? state.selectedPatterns.filter(
                          (id) => id !== action.patternId
                      )
                    : [...state.selectedPatterns, action.patternId],
            };

        case 'CLEAR_MATCHES':
            return { ...state, patternMatches: [], selectedPatterns: [] };

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

        case 'SET_DECOMPOSITION':
            return { ...state, decomposition: action.decomposition };

        case 'CLEAR_DECOMPOSITION':
            return { ...state, decomposition: null };

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
    getModel: (state) => state.selectedModel,
    isGenerating: (state) => state.isGenerating,
    isMatching: (state) => state.isMatching,
    isAnalyzing: (state) => state.isAnalyzing,
    getPatternMatches: (state) => state.patternMatches,
    getSelectedPatterns: (state) => state.selectedPatterns,
    getClarificationQuestions: (state) => state.clarificationQuestions,
    getClarificationAnswers: (state) => state.clarificationAnswers,
    getDecomposition: (state) => state.decomposition,
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
