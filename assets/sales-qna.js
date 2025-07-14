JSUtils.domReady(function () {
    const salesQnA = new SalesQnA();
    salesQnA.init();
});

class SalesQnA {
    state = StateManagerFactory();

    currentIntentId = null;
    originalAnswer = '';

    init = async () => {
        this.state.listen('intents', () => this.renderIntentList(''));

        this.reloadIntends();
        this.intentSearch();

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
                input.classList.add('question-input-pending');
                const index = input.id.split('-')[2];
                this.updateQuestion(index, input);
            } else if (input.classList.contains('tags-input')) {
                this.saveTagEdit(input.dataset.index);
            }
        }, true);

    }

    showStatus = (message, type = 'success') => {
        const statusEl = document.getElementById('statusMessage');
        statusEl.textContent = message;
        statusEl.className = `status-message status-${type}`;
        statusEl.style.display = 'block';

        setTimeout(() => {
            statusEl.style.display = 'none';
        }, 3000);
    }

    reloadIntends = async () => {
        const res = await fetch('/wp-json/sales-qna/v1/intents/get', {
            method: 'GET'
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

        let intentsData = this.state.get('intents');

        const filteredIntents = Object.entries(intentsData).filter(([id, intent]) =>
            intent.name.toLowerCase().includes(filter.toLowerCase())
        );
        if (filteredIntents.length === 0) {
            intentList.innerHTML = `
                    <div class="empty-state">
                        <div class="empty-state-icon">🔍</div>
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

        // Update intent title
        document.getElementById('intentTitle').textContent = intent.name;

        // Update answer
        document.getElementById('answerText').value = intent.answer;
        this.originalAnswer = intent.answer;

        // Update questions
        this.renderQuestions(intent.questions);
        //updateQuestionCounter();
    }

    createNewIntent = () => {
        document.getElementById('newIntentForm').style.display = 'block';
        document.getElementById('newIntentName').focus();
    }

    saveNewIntent = () => {
        const nameEl = document.getElementById('newIntentName');
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
        const newId = Math.max(...Object.keys(intentsData).map(Number)) + 1;

        // Add to data
        intentsData[newId] = {
            name: name,
            answer: '',
            questions: []
        };

        this.renderIntentList();
        this.selectIntent(newId);

        // Hide form
        this.cancelNewIntent();

        this.apiRequest({
            url: '/wp-json/sales-qna/v1/intents/save',
            body: {name: name}
        }).then(() => {
            this.showStatus(`Intent "${name}" created successfully`);
            this.reloadIntends();
        });
    }

    cancelNewIntent = () => {
        document.getElementById('newIntentForm').style.display = 'none';
        document.getElementById('newIntentName').value = '';
    }

    editIntent = () => {
        if (!this.currentIntentId) return;

        const intentsData = this.state.get('intents');
        const intent = intentsData[this.currentIntentId];
        const editForm = document.getElementById('intentEditForm');
        const editNameInput = document.getElementById('editIntentName');

        editNameInput.value = intent.name;
        editForm.style.display = 'block';
        editNameInput.focus();
    }

    saveEditIntent = () => {
        if (!this.currentIntentId) return;

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
        if (!this.currentIntentId) return;

        const intentsData = this.state.get('intents');
        const intent = intentsData[this.currentIntentId];
        const questionCount = intent.questions.length;

        let confirmMessage = `Are you sure you want to delete the intent "${intent.name}"?`;
        if (questionCount > 0) {
            confirmMessage += `\n\nThis will also delete ${questionCount} associated question${questionCount > 1 ? 's' : ''}.`;
        }

        const confirmed = await this.showConfirmDialog({
            icon: '🗑️',
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
        if (!this.currentIntentId) return;

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
        if (!this.currentIntentId) return;

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
        if (!this.currentIntentId) return;

        const value = input.value;
        let intentsData = this.state.get('intents');

        if (value.length === 0) {
            this.showStatus('Please enter a question', 'error');
            this.removeQuestion(index);
            return;
        }

        intentsData[this.currentIntentId].questions[index] = {
            text: value.trim()
        }

        if (value.trim()) {
            const data = {
                question: value,
                intent_id: intentsData[this.currentIntentId].id
            }
            this.apiRequest({
                url: '/wp-json/sales-qna/v1/questions/save',
                body: data
            }).then((res) => {
                input.classList.remove('question-input-pending');
                input.disabled = true;

                intentsData[this.currentIntentId].questions[index] = {
                    id: res.id,
                    text: value.trim()
                }
                this.renderQuestions(intentsData[this.currentIntentId].questions);
            }).catch(() => {
                this.removeQuestion(index);
                this.showStatus('Question not added - server error', 'error');
            });
        }
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
        if (!this.currentIntentId) return;

        const intentsData = this.state.get('intents');

        const confirmed = await this.showConfirmDialog({
            icon: '🗑️',
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
            .replace(/[^a-z0-9\-_\s]/g, '')
            .replace(/\s+/g, ' ')
            .replace(/^-+|-+$/g, '')
            .trim();
    }
    parseTags = (tagString) => {
        if (!tagString || typeof tagString !== 'string') return [];

        return tagString
            .split(',')
            .map(tag => this.validateTag(tag))
            .filter(tag => tag)
            .slice(0, 10);
    }

    enterTagEditMode = (questionIndex) => {
        const displayMode = document.getElementById(`tagsDisplay${questionIndex}`);
        const editMode = document.getElementById(`tagsEdit${questionIndex}`);
        const input = document.getElementById(`tagsInput${questionIndex}`);

        displayMode.style.display = 'none';
        editMode.classList.add('active');

        // Focus on input and select all text
        setTimeout(() => {
            input.focus();
            input.select();
        }, 100);
    }

    exitTagEditMode = (questionIndex) => {
        const displayMode = document.getElementById(`tagsDisplay${questionIndex}`);
        const editMode = document.getElementById(`tagsEdit${questionIndex}`);

        editMode.classList.remove('active');
        displayMode.style.display = 'block';
    }

    saveTagEdit = (questionIndex) => {
        if (!this.currentIntentId) return;

        const input = document.getElementById(`tagsInput${questionIndex}`);
        const tagString = input.value;
        const tags = this.parseTags(tagString);

        const intentsData = this.state.get('intents');
        const question = intentsData[this.currentIntentId].questions[questionIndex];

        if (typeof question === 'string') {
            intentsData[this.currentIntentId].questions[questionIndex] = {
                text: question,
                tags: tags
            };
        } else {
            question.tags = tags;
        }

        this.updateTagsDisplay(questionIndex, tags);
        this.exitTagEditMode(questionIndex);
        this.renderIntentList();

        const data = {
            id: intentsData[this.currentIntentId].questions[questionIndex].id,
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
        if (!this.currentIntentId) return;

        const intentsData = this.state.get('intents');
        const question = intentsData[this.currentIntentId].questions[questionIndex];
        const originalTags = typeof question === 'object' ? (question.tags || []) : [];
        const input = document.getElementById(`tagsInput${questionIndex}`);

        input.value = originalTags.join(', ');

        this.exitTagEditMode(questionIndex);
    }

    updateTagsDisplay = (questionIndex, tags) => {
        const displayContainer = document.querySelector(`#tagsDisplay${questionIndex} .tags-display`);

        displayContainer.innerHTML = this.renderTagsDisplay(tags);

        // Update empty state class
        const displayMode = document.getElementById(`tagsDisplay${questionIndex}`);
        if (tags.length === 0) {
            displayMode.classList.add('empty');
        } else {
            displayMode.classList.remove('empty');
        }
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
                    <input id="question-input-${index}" type="text" class="question-input" value="${question.text ?? ''}" ${hasText ? 'disabled' : ''}>
                    <div class="question-actions">
                        <button id="delete-question" data-question-id="${question.id}" data-question-index="${index}" class="btn btn-danger">     
                            🗑️
                        </button>
                    </div>
                </div>
                <div class="question-tags-section">
            <div class="tags-container">
                <!-- Display Mode -->
                <div class="tags-display-mode ${questionTags.length === 0 ? 'empty' : ''}" data-index="${index}" id="tagsDisplay${index}">
                    <div class="tags-label">
                        🏷️ Tags 
                        <span class="tags-edit-hint">Click to edit</span>
                    </div>
                    <div class="tags-display">
                        ${this.renderTagsDisplay(questionTags)}
                    </div>
                </div>
                
                <!-- Edit Mode -->
                <div class="tags-edit-mode" id="tagsEdit${index}">
                    <div class="tags-input-group">
                        <div class="tags-label">✏️ Edit Tags</div>
                        <input type="text" class="tags-input" id="tagsInput${index}" data-index="${index}" value="${questionTags.join(', ')}"
                               placeholder="Enter tags separated by commas (e.g. pricing, support, features)">
                        <div class="tags-actions">
                            <button id="save-tag-edit" class="tags-btn tags-btn-save" data-index="${index}">
                                💾 Save
                            </button>
                            <button id="cancel-tag-edit" class="tags-btn tags-btn-cancel"  data-index="${index}">
                                ❌ Cancel
                            </button>
                        </div>
                    </div>
                </div>
            `;
        return div;
    }

    apiRequest = async ({url, method = 'POST', body = {}, showError = true, showSuccess = false}) => {
        try {
            const response = await fetch(url, {
                method,
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify(body)
            });

            const data = await response.json();

            if (!response.ok) {
                if (showError) {
                    window.notifications.show(data.message || 'Request failed', 'error');
                }
                throw new Error(data.message || 'Request failed');
            }

            if (showSuccess) {
                this.showStatus('Request successful');
            }

            return data;
        } catch (error) {
            if (error.name !== 'AbortError' && showError) {
                window.notifications.show(error.message || 'Failed to process request', 'error');
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
        icon.textContent = options.icon || '🗑️';
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
}
