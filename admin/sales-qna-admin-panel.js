JSUtils.domReady(function () {
    const salesQnA = new SalesQnaAdminPanel();
    salesQnA.init();
});

class SalesQnaAdminPanel {
    state = StateManagerFactory();

    currentIntentId = null;
    originalAnswer = '';
    searchedString = ''

    init = async () => {
        this.state.listen('intents', () => this.renderIntentList(''));

        this.reloadIntends();
        this.intentSearch();
        this.loadSettings();

        JSUtils.addGlobalEventListener(document, '#open-settings-button', 'click', this.toggleSettings);
        JSUtils.addGlobalEventListener(document, '#close-settings-button', 'click', this.toggleSettings);
        JSUtils.addGlobalEventListener(document, '#settings-overlay', 'click', this.toggleSettings);
        JSUtils.addGlobalEventListener(document, '#save-settings-button', 'click', this.saveSettings);
        JSUtils.addGlobalEventListener(document, '#toggle-rtl-label', 'click', this.toggleRTL);
        JSUtils.addGlobalEventListener(document, '#toggle-rtl-switch', 'click', this.toggleRTL);

        JSUtils.addGlobalEventListener(document, '#sales-qna-export', 'click', this.handleExport);
        JSUtils.addGlobalEventListener(document, '#sales-qna-import', 'click', function(e) {
            document.getElementById('sales-qna-import-form').click();
        });
        JSUtils.addGlobalEventListener(document, '#sales-qna-import-form', 'change', this.handleImport);

        JSUtils.addGlobalEventListener(document, '#create-new-intent', 'click', this.createNewIntent);
        JSUtils.addGlobalEventListener(document, '#save-new-intent', 'click', this.saveNewIntent);
        JSUtils.addGlobalEventListener(document, '#cancel-new-intent', 'click', this.cancelNewIntent);
        JSUtils.addGlobalEventListener(document, '#edit-intent', 'click', this.editIntent);
        JSUtils.addGlobalEventListener(document, '#delete-intent', 'click', this.deleteIntent);
        JSUtils.addGlobalEventListener(document, '#save-edit-intent', 'click', this.saveEditIntent);
        JSUtils.addGlobalEventListener(document, '#cancel-edit-intent', 'click', this.cancelEditIntent);

        JSUtils.addGlobalEventListener(document, '#save-answer', 'click', () => this.saveAnswer());

        JSUtils.addGlobalEventListener(document, '#add-new-question', 'click', this.addNewQuestion);
        JSUtils.addGlobalEventListener(document, '#delete-question', 'click', this.doDeleteQuestion);

        JSUtils.addGlobalEventListener(document, '#cancel-tag-edit', 'click', (event) => {
            const index = event.target.dataset.index;
            this.cancelTagEdit(index);
        });
        JSUtils.addGlobalEventListener(document, '#save-tag-edit', 'click', (event) => {
            const index = event.target.dataset.index;
            this.saveTagEdit(index);
        });

        JSUtils.addGlobalEventListener(document, '.tags-display-mode', 'click', (event) => {
            const el = event.target.closest('.tags-display-mode');
            if (!el) return;

            const index = el.dataset.index;
            this.enterTagEditMode(index);
        });


        document.addEventListener('keypress', this.handleEnterKeyPress);
        document.addEventListener('blur', (event) => {
            const input = event.target;
            if (input.classList.contains('question-input')) {
                const index = input.id.split('-')[2];
                this.updateQuestion(index, input);
            } else if (input.classList.contains('tags-input')) {
                this.saveTagEdit(input.dataset.index);
            }
        }, true);
    }

    showStatus = (message, type = 'success') => {
        const statusEl = document.getElementById('statusMessage');
        const container = document.querySelector('.sales-qna-container');
        statusEl.textContent = message;
        statusEl.className = `status-message status-${type}`;
        statusEl.style.display = 'block';

        if (statusEl && container) {
            const containerRect = container.getBoundingClientRect();
            statusEl.style.left = `${containerRect.left + containerRect.width / 2}px`;
        }

        setTimeout(() => {
            statusEl.style.display = 'none';
        }, 3000);
    }

    reloadIntends = async () => {
        const res = await fetch('/wp-json/sales-qna/v1/intents/get', {
            method: 'POST'
        });
        if (!res.ok) {
            const error = await res.json();
        } else {
            const data = await res.json();
            this.state.set('intents', data);
        }
    }

    renderIntentList = (filter = '') => {
        const intentList = document.getElementById('intentList');
        intentList.innerHTML = '';

        this.searchedString = '';

        let intentsData = this.state.get('intents');

        const lowerFilter = filter.toLowerCase().trim();
        const hasFilter = lowerFilter.length > 0;

        const filteredIntents = Object.entries(intentsData).filter(([id, intent]) => {
            if (!hasFilter) return true; // Show all if no filter

            const nameMatches = intent.name.toLowerCase().includes(lowerFilter);
            const questionsMatch = intent.questions?.some(question =>
                question.text?.toLowerCase().includes(lowerFilter)
            );

            this.searchedString = lowerFilter;
            return nameMatches || questionsMatch;
        });

        if (filteredIntents.length === 0) {
            intentList.innerHTML = `
                    <div class="empty-state">
                        <div class="empty-state-icon">
                            <i class="fa-solid fa-magnifying-glass"></i>
                        </div>
                        <p>No intents found</p>
                    </div>
                `;
            return;
        }

        filteredIntents.forEach(([id, intent]) => {
            const intentItem = document.createElement('div');
            intentItem.className = 'intent-item';
            intentItem.onclick = () => this.selectIntent(id);

            intentItem.innerHTML = `
                    <div class="intent-name">${intent.name}</div>
                    <div class="intent-meta">
                        <span>${intent.questions.length} questions</span>
                        <span>${intent.answer ? '✓' : '○'}</span>
                    </div>
                `;

            intentList.appendChild(intentItem);
        });
    }

    intentSearch = () => {
        const searchInput = document.getElementById('intentSearch');
        searchInput.addEventListener('input', (e) => {
            this.renderIntentList(e.target.value);
        });
    }

    selectIntent = (intentId, event = null) => {
        const intentItems = document.querySelectorAll('.intent-item');
        intentItems.forEach(item => item.classList?.remove('active'));

        let elementToActivate = event?.currentTarget;
        if (!elementToActivate) {
            elementToActivate = Array.from(intentItems).find(
                item => item?.dataset?.id === intentId.toString()
            ) || intentItems[intentItems.length - 1];
        }

        elementToActivate?.classList?.add('active');


        this.currentIntentId = intentId;

        const intent = this.state.get('intents')?.[intentId];
        if (intent) {
            this.showIntentContent(intent);
        }
    };

    showIntentContent = (intent) => {
        document.getElementById('emptyState').style.display = 'none';
        document.getElementById('intentContent').style.display = 'block';

        document.getElementById('intentTitle').textContent = intent.name;

        document.getElementById('answerText').value = intent.answer;
        this.originalAnswer = intent.answer;

        this.renderTags(intent.tags);
        this.renderQuestions(intent.questions);
        this.updateQuestionCounter();
    }

    createNewIntent = () => {
        document.getElementById('newIntentForm').style.display = 'block';
        document.getElementById('intent-input').focus();
    }

    saveNewIntent = () => {
        const nameEl = document.getElementById('intent-input');
        const name = nameEl.value.trim();

        if (!name) {
            this.showStatus('Please enter an intent name', 'error');
            return;
        }

        const intentsData = this.state.get('intents');

        // Check for duplicate names
        const existingNames = Object.values(intentsData).map(intent => intent.name.toLowerCase());
        if (existingNames.includes(name.toLowerCase())) {
            this.showStatus('An intent with this name already exists', 'error');
            return;
        }

        // Generate new ID
        const existingIds = Object.keys(intentsData).map(Number);
        const newId = (existingIds.length ? Math.max(...existingIds) + 1 : 0);

        // Add to data
        intentsData[newId] = {
            name: name,
            answer: '',
            questions: []
        };

        this.renderIntentList();

        // Hide form
        this.cancelNewIntent();


        this.apiRequest({
            url: '/wp-json/sales-qna/v1/intents/save',
            body: {name: name}
        }).then(() => {
            this.showStatus(`Intent "${name}" created successfully`);
            this.reloadIntends();
            this.selectIntent(newId);
        });
    }

    cancelNewIntent = () => {
        document.getElementById('newIntentForm').style.display = 'none';
        document.getElementById('intent-input').value = '';
    }

    editIntent = () => {
        if (this.currentIntentId === null || this.currentIntentId === undefined) return;

        const intentsData = this.state.get('intents');
        const intent = intentsData[this.currentIntentId];
        const editForm = document.getElementById('intentEditForm');
        const editNameInput = document.getElementById('editIntentName');

        editNameInput.value = intent.name;
        editForm.style.display = 'block';
        editNameInput.focus();
    }

    saveEditIntent = () => {
        if (this.currentIntentId === null || this.currentIntentId === undefined) return;

        const newName = document.getElementById('editIntentName').value.trim();

        if (!newName) {
            this.showStatus('Please enter an intent name', 'error');
            return;
        }

        const intentsData = this.state.get('intents');

        // Check for duplicate names (excluding current intent)
        const existingNames = Object.entries(intentsData)
            .filter(([id, intent]) => id !== this.currentIntentId)
            .map(([id, intent]) => intent.name.toLowerCase());

        if (existingNames.includes(newName.toLowerCase())) {
            this.showStatus('An intent with this name already exists', 'error');
            return;
        }

        intentsData[this.currentIntentId].name = newName;
        document.getElementById('intentTitle').textContent = newName;

        this.renderIntentList();
        this.cancelEditIntent();

        const data = {
            id: intentsData[this.currentIntentId].id,
            name: newName
        };

        this.apiRequest({
            url: '/wp-json/sales-qna/v1/intents/save',
            body: data
        }).then(() => {
            this.showStatus(`Intent renamed to "${newName}"`);
        });
    }

    cancelEditIntent = () => {
        document.getElementById('intentEditForm').style.display = 'none';
        document.getElementById('editIntentName').value = '';
    }

    deleteIntent = async () => {
        if (this.currentIntentId === null || this.currentIntentId === undefined) return;

        const intentsData = this.state.get('intents');
        const intent = intentsData[this.currentIntentId];
        const questionCount = intent.questions.length;

        let confirmMessage = `Are you sure you want to delete the intent "${intent.name}"?`;
        if (questionCount > 0) {
            confirmMessage += `\n\nThis will also delete ${questionCount} associated question${questionCount > 1 ? 's' : ''}.`;
        }

        const confirmed = await this.showConfirmDialog({
            type: 'danger',
            title: 'Delete Intent',
            message: confirmMessage,
            actionText: 'Delete Intent',
            details: [
                { label: 'Intent', value: intent.name },
                { label: 'Questions', value: questionCount },
            ]
        });

        if (confirmed) {
            const id = intentsData[this.currentIntentId].id;
            // Remove from data
            delete intentsData[this.currentIntentId];

            // Refresh list
            this.renderIntentList();

            this.apiRequest({
                url: '/wp-json/sales-qna/v1/intents/delete',
                body: {id: id}
            }).then(() => {
                this.showStatus(`Intent "${intent.name}" deleted successfully`);

                // Show empty state
                document.getElementById('emptyState').style.display = 'flex';
                document.getElementById('intentContent').style.display = 'none';
            });

            this.currentIntentId = null;
        }
    }

    saveAnswer() {
        if (this.currentIntentId === null || this.currentIntentId === undefined) return;

        const answerText = document.getElementById('answerText').value.trim();

        if (!answerText) {
            this.showStatus('Please enter an answer', 'error');
            return;
        }

        const intentsData = this.state.get('intents');
        intentsData[this.currentIntentId].answer = answerText;
        this.originalAnswer = answerText;

        // Refresh the list to update the checkmark
        this.renderIntentList();

        const data = {
            id: intentsData[this.currentIntentId].id,
            answer: this.originalAnswer
        };

        this.apiRequest({
            url: '/wp-json/sales-qna/v1/intents/save',
            method: 'POST',
            body: data,
            showSuccess: true
        }).then(() => {
            this.showStatus('Answer saved successfully');
        });
    }

    addNewQuestion = () => {
        if (this.currentIntentId === null || this.currentIntentId === undefined) return;

        const intentsData = this.state.get('intents');
        const newQuestion = '';

        intentsData[this.currentIntentId].questions.push(newQuestion);

        const questionsList = document.getElementById('questionsList');
        const index = intentsData[this.currentIntentId].questions.length - 1;
        const questionEl = this.createQuestionElement(newQuestion, index);
        questionsList.appendChild(questionEl);

        // Focus on the new question input
        const input = questionEl.querySelector('.question-input');
        input.focus();


        this.updateQuestionCounter();
        this.renderIntentList();
    }

    updateQuestion = (index, input) => {
        if (this.currentIntentId === null || this.currentIntentId === undefined) return;

        const value = input.value.trim();
        if (value.length === 0) {
            this.showStatus('Please enter a question', 'error');
            this.removeQuestion(index);
            return;
        }

        const intentsData = this.state.get('intents');
        const intent = intentsData[this.currentIntentId];
        const question = intent.questions[index] || {};

        const existingText = question.text ?? '';
        const existingId = question.id ?? '';

        const isUpdate = existingText !== '';
        const isSame = existingText === value;

        if (isSame) return;

        // update the local state
        intent.questions[index] = {
            id: existingId,
            text: value
        };

        const requestData = {
            question: value,
            intent_id: intent.id,
            id: existingId
        };

        this.apiRequest({
            url: '/wp-json/sales-qna/v1/questions/save',
            body: requestData
        })
            .then((res) => {
                // Update with confirmed ID from server
                intent.questions[index] = {
                    id: res.id,
                    text: value
                };
                this.renderQuestions(intent.questions);
                this.showStatus(isUpdate ? 'Question updated' : 'Question added');
            })
            .catch((error) => {
                this.removeQuestion(index);
                this.showStatus(error, 'error');
            });
    }

    removeQuestion = (index) => {
        const intentsData = this.state.get('intents');
        intentsData[this.currentIntentId].questions.splice(index, 1);
        this.renderQuestions(intentsData[this.currentIntentId].questions);
        this.updateQuestionCounter();
        this.renderIntentList();
    }

    doDeleteQuestion = e => {
        const button = e.target.closest('#delete-question');
        if (button) {
            const id = parseInt(button.dataset.questionId);
            const index = parseInt(button.dataset.questionIndex);
            this.deleteQuestion(id, index);
        }
    }

    deleteQuestion = async (id, index) => {
        if (this.currentIntentId === null || this.currentIntentId === undefined) return;

        const intentsData = this.state.get('intents');

        const confirmed = await this.showConfirmDialog({
            type: 'danger',
            title: 'Delete Question',
            message: `Are you sure you want to delete this question? This action cannot be undone.`,
            actionText: 'Delete Question',
            details: [
                { label: 'Question', value: intentsData[this.currentIntentId].questions[index].text },
            ]
        });

        if (confirmed) {
            const data = {
                id: intentsData[this.currentIntentId].questions[index].id,
                intent_id: intentsData[this.currentIntentId].id
            }

            this.apiRequest({
                url: '/wp-json/sales-qna/v1/questions/delete',
                body: data
            }).then(() => {
                this.showStatus('Question deleted');
            });

            this.removeQuestion(index);
        }
    }

    handleEnterKeyPress = (event) => {
        const input = event.target;
        if (!input.classList.contains('question-input') && !input.classList.contains('tags-input')) return;

        // Only trigger on an Enter key
        if (event.key === 'Enter') {
            input.blur();
        }
    }

    updateQuestionCounter = () => {
        if (!this.currentIntentId) return;

        const intentsData = this.state.get('intents');
        const count = intentsData[this.currentIntentId].questions.length;
        document.getElementById('questionCounter').textContent = `${count} questions`;
    }

    renderQuestions = (questions) => {
        const questionsList = document.getElementById('questionsList');
        questionsList.innerHTML = '';

        questions.forEach((question, index) => {
            const questionEl = this.createQuestionElement(question, index);
            questionsList.appendChild(questionEl);
        });
    }

    validateTag = (tag) => {
        return tag.trim()
            .toLowerCase()
            .replace(/[^\p{L}\p{N}\-_ ]+/gu, '')  // allow letters (all alphabets), numbers, dash, underscore, and space
            .replace(/\s+/g, ' ')                // collapse multiple spaces into one
            .replace(/^-+|-+$/g, '')             // trim leading/trailing dashes
            .trim();
    };

    parseTags = (tagString) => {
        if (!tagString || typeof tagString !== 'string') return [];

        return tagString
            .split(',')
            .map(tag => this.validateTag(tag))
            .filter(tag => tag)
            .slice(0, 10);
    }

    enterTagEditMode = () => {
        const displayMode = document.getElementById(`tagsDisplay`);
        const editMode = document.getElementById(`tagsEdit`);
        const input = document.getElementById(`tagsInput`);

        displayMode.style.display = 'none';
        editMode.classList.add('active');

        // Focus on input and select all text
        setTimeout(() => {
            input.focus();
            input.select();
        }, 100);
    }

    exitTagEditMode = () => {
        const displayMode = document.getElementById(`tagsDisplay`);
        const editMode = document.getElementById(`tagsEdit`);

        editMode.classList.remove('active');
        displayMode.style.display = 'block';
    }

    saveTagEdit = (questionIndex) => {
        if (this.currentIntentId === null || this.currentIntentId === undefined) return;

        const input = document.getElementById(`tagsInput`);
        const tagString = input.value;
        const tags = this.parseTags(tagString);

        if (tags.length === 0) return;

        const intentsData = this.state.get('intents');

        const intent = intentsData[this.currentIntentId];


        if (typeof intent === 'string') {
            intentsData[this.currentIntentId].tags = {
                text: question,
                tags: tags
            };
        } else {
            intent.tags = tags;
        }

        this.updateTagsDisplay(questionIndex, tags);
        this.exitTagEditMode(questionIndex);
        this.renderIntentList();

        const data = {
            id: intentsData[this.currentIntentId].id,
            tags: tags,
        };

        this.apiRequest({
            url: '/wp-json/sales-qna/v1/tags/save',
            body: data
        }).catch((error) => {
            this.showStatus(error.message || 'An error occurred', 'error');
        });
    }

    cancelTagEdit = (questionIndex) => {
        if (this.currentIntentId === null || this.currentIntentId === undefined) return;

        const intentsData = this.state.get('intents');
        const intent = intentsData[this.currentIntentId];
        const originalTags = typeof intent === 'object' ? (intent.tags || []) : [];
        const input = document.getElementById(`tagsInput`);

        input.value = originalTags.join(', ');

        this.exitTagEditMode(questionIndex);
    }

    updateTagsDisplay = (questionIndex, tags) => {
        const displayContainer = document.querySelector(`#tagsDisplay .tags-display`);

        displayContainer.innerHTML = this.renderTagsDisplay(tags);

        // Update empty state class
        const displayMode = document.getElementById(`tagsDisplay`);
        if (tags.length === 0) {
            displayMode.classList.add('empty');
        } else {
            displayMode.classList.remove('empty');
        }
    }

    renderTags = (tags) => {
        const tagsList = document.getElementById('tagsList');
        tags = typeof tags === 'object' ? (tags || []) : [];

        tagsList.innerHTML = `
        <div class="intents-tags-section">
            <div class="tags-container">
                <!-- Display Mode -->
                <div class="tags-display-mode ${tags.length === 0 ? 'empty' : ''}" id="tagsDisplay">
                    <div class="tags-label">
                        <i class="fa-solid fa-tags"></i> Tags
                        <span class="tags-edit-hint">Click to edit</span>
                    </div>
                    <div class="tags-display">
                        ${this.renderTagsDisplay(tags)}
                    </div>
                </div>
    
                <!-- Edit Mode -->
                <div class="tags-edit-mode" id="tagsEdit">
                    <div class="tags-input-group">
                        <divclass="tags-label">
                            <i class="fa-solid fa-pen"></i> 
                            Edit Tags
                        </div>
                        <input type="text" class="tags-input" id="tagsInput" value="${tags.join(', ')}"
                               placeholder="Enter tags separated by commas (e.g. pricing, support, features)">
                            <div class="tags-actions">
                                <button id="save-tag-edit" class="tags-btn tags-btn-save">
                                    <i class="fa-solid fa-floppy-disk"></i> Save
                                </button>
                                <button id="cancel-tag-edit" class="tags-btn tags-btn-cancel">
                                    <i class="fa-solid fa-xmark"></i> Cancel
                                </button>
                            </div>
                    </div>
                </div>
            </div>
        </div>
        `;
    }

    renderTagsDisplay = (tags) => {

        if (!tags || tags.length === 0) {
            return '<div class="tags-empty-interactive">+ Click to add tags</div>';
        }

        return tags.map(tag => `
        <span class="tag-item">${tag}</span>
    `).join('');
    }

    createQuestionElement = (question, index) => {
        const div = document.createElement('div');

        // Determine if input should be focusable
        const hasText = question.text && question.text.trim().length > 0;

        const questionTags = typeof question === 'object' ? (question.tags || []) : [];
        const tagString = questionTags.join(', ')

        div.className = 'question-item';
        div.innerHTML = `
                <div class="question-content">
                    <input id="question-input-${index}" type="text" class="question-input" value="${question.text ?? ''}">
                    <div class="question-actions">
                        <button id="delete-question" data-question-id="${question.id}" data-question-index="${index}" class="btn btn-danger">     
                            <i class="fa-solid fa-trash"></i>
                        </button>
                    </div>
                </div>
                `;

        // Highlight matching text in the input (if searchedString exists)
        if (this.searchedString && question.text) {
            const input = div.querySelector(`#question-input-${index}`);
            this.highlightSearchInput(input, this.searchedString);
        }

        return div;
    }

    apiRequest = async ({url, method = 'POST', body = {}, showError = true, showSuccess = false}) => {
        try {
            const headers = {
                'Content-Type': 'application/json',
                ...(SalesQnASettings.nonce && { 'X-WP-Nonce': SalesQnASettings.nonce })
            };

            const response = await fetch(url, {
                method,
                headers,
                body: JSON.stringify(body)
            });

            const data = await response.json();

            if (!response.ok) {
                if (showError) {
                    this.showStatus(data.message || 'Request failed', 'error')
                }
                throw new Error(data.message || 'Request failed');
            }

            if (showSuccess) {
                this.showStatus('Request successful');
            }

            return data;
        } catch (error) {
            if (error.name !== 'AbortError' && showError) {
                this.showStatus(error.message || 'Failed to process request', 'error');
            }
            throw error;
        }
    };

    // Custom Confirm Dialog Functions
    showConfirmDialog = (options) => {
        const overlay = document.getElementById('confirmOverlay');
        const icon = document.getElementById('confirmIcon');
        const title = document.getElementById('confirmTitle');
        const message = document.getElementById('confirmMessage');
        const details = document.getElementById('confirmDetails');
        const actionBtn = document.getElementById('confirmAction');
        const cancelBtn = document.getElementById('confirmCancel');

        // Set content
        icon.className = `confirm-icon ${options.type || 'danger'}`;
        title.textContent = options.title || 'Confirm Action';
        message.textContent = options.message || 'Are you sure you want to proceed?';
        actionBtn.textContent = options.actionText || 'Delete';
        actionBtn.className = `confirm-btn confirm-btn-${options.type || 'danger'}`;

        // Set details if provided
        if (options.details && options.details.length > 0) {
            details.style.display = 'block';
            details.innerHTML = options.details.map(detail => `
                    <div class="confirm-detail-item">
                        <span class="confirm-detail-label">${detail.label}:</span>
                        <span class="confirm-detail-value">${detail.value}</span>
                    </div>
                `).join('');
        } else {
            details.style.display = 'none';
        }

        // Show dialog
        overlay.classList.add('show');

        // Return promise
        return new Promise((resolve) => {
            const handleAction = () => {
                overlay.classList.remove('show');
                actionBtn.removeEventListener('click', handleAction);
                cancelBtn.removeEventListener('click', handleCancel);
                overlay.removeEventListener('click', handleOverlayClick);
                resolve(true);
            };

            const handleCancel = () => {
                overlay.classList.remove('show');
                actionBtn.removeEventListener('click', handleAction);
                cancelBtn.removeEventListener('click', handleCancel);
                overlay.removeEventListener('click', handleOverlayClick);
                resolve(false);
            };

            const handleOverlayClick = (e) => {
                if (e.target === overlay) {
                    handleCancel();
                }
            };

            actionBtn.addEventListener('click', handleAction);
            cancelBtn.addEventListener('click', handleCancel);
            overlay.addEventListener('click', handleOverlayClick);
        });
    }

    toggleSettings = () => {
        const panel = document.getElementById('settingsPanel');
        const overlay = document.querySelector('.settings-overlay');

        panel.classList.toggle('open');
        overlay.classList.toggle('active');

        // Prevent body scroll when panel is open
        if (panel.classList.contains('open')) {
            document.body.style.overflow = 'hidden';
        } else {
            document.body.style.overflow = '';
        }
    }

    closeSettings = () =>{
        const panel = document.getElementById('settingsPanel');
        const overlay = document.querySelector('.settings-overlay');

        panel.classList.remove('open');
        overlay.classList.remove('active');
        document.body.style.overflow = '';
    }

    loadSettings = () => {
        const direction = SalesQnASettings.direction;
        const apiKey = SalesQnASettings.apiKey;

        if (direction === 'rtl') {
            document.querySelector('.sales-qna-container')?.classList.add('rtl');
            document.getElementById('toggle-rtl-switch').classList.add('active');
        }

        if (apiKey) {
            document.getElementById('apiKey').value = apiKey;
        }
    }

    saveSettings = () => {
        const apiKeyInput = document.getElementById('apiKey');
        const dir = document.querySelector('.sales-qna-container')?.classList.contains('rtl') ? 'rtl' : 'ltr';

        const apiKey = apiKeyInput.value.trim();

        const data = {
            apiKey: apiKey,
            direction: dir
        }
        this.apiRequest({
            url: '/wp-json/sales-qna/v1/settings/save',
            body: data
        }).then(() => {
            this.showStatus('Settings saved successfully!', 'success');
            setTimeout(this.closeSettings, 1500);
        }).catch((error) => {
            this.showStatus(error.message || 'An error occurred', 'error');
        });
    }

    toggleRTL = () => {
        const toggle = document.getElementById('toggle-rtl-switch');
        const isActive = toggle.classList.contains('active');
        const confirmOverlay = document.getElementById('confirmOverlay');

        if (isActive) {
            toggle.classList.remove('active');
            document.querySelector('.sales-qna-container')?.classList.remove('rtl');
            confirmOverlay?.classList.remove('rtl');
        } else {
            toggle.classList.add('active');
            document.querySelector('.sales-qna-container')?.classList.add('rtl');
            confirmOverlay?.classList.add('rtl');
        }
    }

    highlightSearchInput = (inputElement, searchTerm) => {
        const text = inputElement.value;
        const lowerSearch = (searchTerm || '').toLowerCase();
        const lowerText = (text || '').toLowerCase();

        if (!text || !searchTerm || !lowerText.includes(lowerSearch)) return;

        const containerIsRTL = document.querySelector('.sales-qna-container')?.classList.contains('rtl');

        const startPercent = (lowerText.indexOf(lowerSearch) / text.length) * 100;
        const endPercent = ((lowerText.indexOf(lowerSearch) + searchTerm.length) / text.length) * 100;

        if (containerIsRTL) {
            inputElement.style.backgroundImage = `
            linear-gradient(
                to left,
                yellow ${100 - startPercent}%,
                yellow ${100 - endPercent}%,
                transparent ${100 - endPercent}%,
                transparent 0%
            )
        `;
        } else {
            inputElement.style.backgroundImage = `
            linear-gradient(
                to right,
                yellow ${startPercent}%,
                yellow ${endPercent}%,
                transparent ${endPercent}%,
                transparent 100%
            )
        `;
        }
    };

    handleImport = async (e) => {
        const importButton = document.getElementById('sales-qna-import');

        importButton.textContent = 'Importing...';
        importButton.disabled = true;

        const file = e.target.files[0];

        if (!file || !file.name.endsWith('.zip')) {
            alert('Please select a valid .zip file');
            return;
        }

        const formData = new FormData();
        formData.append('file', file);

        try {
            const res = await fetch('/wp-json/sales-qna/v1/settings/import', {
                method: 'POST',
                headers: {
                    'X-WP-Nonce': SalesQnASettings.nonce
                },
                body: formData
            });

            const result = await res.json();
            if (res.ok) {
                this.showStatus('Import Successful');

                importButton.textContent = 'Imported!';

                setTimeout(() => {
                    importButton.textContent = 'Import Q&A';
                    importButton.disabled = false;
                    e.target.value = '';
                    this.reloadIntends();
                }, 2000);
            } else {
                this.showStatus('Import failed: ' + (result.error || 'Unknown error'), 'error');
            }
        } catch (err) {
            console.error(err);
            this.showStatus('Import failed due to network or server error.', 'error');
        }
    }

    handleExport = async () => {
        try {
            const exportButton = document.getElementById('sales-qna-export');

            exportButton.textContent = 'Exporting...';
            exportButton.disabled = true;

            const response = await fetch('/wp-json/sales-qna/v1/settings/export', {
                method: 'GET',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': SalesQnASettings.nonce
                },
            });

            const blob = await response.blob();
            const url = URL.createObjectURL(blob);
            const filename = response.headers.get('Content-Disposition')?.match(/filename="(.+?)"/)?.[1]
                || `sales_qna_export_${new Date().toISOString().split('T')[0]}.zip`;

            const a = document.createElement('a');
            a.href = url;
            a.download = filename;
            document.body.appendChild(a);
            a.click();

            setTimeout(() => {
                URL.revokeObjectURL(url);
                document.body.removeChild(a);
            }, 100);

            this.showStatus('Export Successful');

            exportButton.textContent = 'Exported!';

            // Reset after 2 seconds
            setTimeout(() => {
                exportButton.textContent = 'Export Q&A';
                exportButton.disabled = false;
            }, 2000);
        } catch (error) {
            console.error('Export failed:', error);
            this.showStatus('Export failed: ' + error.message, error);
        }
    }
}
