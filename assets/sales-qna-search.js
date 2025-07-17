JSUtils.domReady(function () {
    const salesQnASearch = new SalesQnASearch();
    salesQnASearch.init();
})

class SalesQnASearch {

    init = () => {
        JSUtils.addGlobalEventListener(document, '#ask-question', 'click', this.askQuestion);
        JSUtils.addGlobalEventListener(document, '#clear-question', 'click', this.clearQuestion);
        JSUtils.addGlobalEventListener(document, '#copy-btn', 'click', (event) => this.handleCopyClick(event));

        document.addEventListener('keypress', this.handleEnterKeyPress);
    }

    askQuestion = async () => {
        const questionInput = document.getElementById('customerQuestion');
        const question = questionInput.value.trim();

        if (!question) {
            alert('Please enter a customer question');
            return;
        }

        this.showLoading(true);
        this.hideResults();

        try {
            const data = {
                search: question
            }

            try {
                const response = await fetch('/wp-json/sales-qna/v1/answers/get', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    credentials: 'same-origin',
                    body: JSON.stringify(data)
                });

                if (!response.ok) {
                    const error = await response.json();
                    throw new Error(error.message || 'Request failed');
                }

                const matches = await response.json();
                this.displayMatches(matches);

            } catch (error) {
                console.error('Search failed:', error);
                throw error;
            }

        } catch (error) {
            console.error('Error analyzing question:', error);
        } finally {
            this.showLoading(false);
        }
    }

    showLoading = (show)  =>{
        const loadingState = document.getElementById('loadingState');
        const askQuestion = document.getElementById('ask-question');

        if (show) {
            loadingState.classList.add('show');
            askQuestion.disabled = true;
            askQuestion.innerHTML = '<span>🔄 Analyzing...</span>';
        } else {
            loadingState.classList.remove('show');
            askQuestion.disabled = false;
            askQuestion.innerHTML = '<span>🔍 Find Matches</span>';
        }
    }

    displayMatches = (matches) => {
        const container = document.getElementById('matchesContainer');
        const matchesList = document.getElementById('matchesList');
        const resultsCount = document.getElementById('resultsCount');
        const noMatches = document.getElementById('noMatches');

        if (matches.length === 0) {
            container.style.display = 'none';
            noMatches.style.display = 'block';
            return;
        }

        noMatches.style.display = 'none';
        resultsCount.textContent = `${matches.length} match${matches.length !== 1 ? 'es' : ''} found`;

        matchesList.innerHTML = matches.map((match, index) => {
            const similarity = Math.round(match.similarity * 100);
            const scoreClass = this.getScoreClass(similarity);
            const scoreLabel = this.getScoreLabel(similarity);

            return `
                    <div class="match-item ${scoreClass}" style="animation-delay: ${index * 0.1}s">
                        <div class="match-header">
                            <div class="match-info">
                                <div class="match-intent">${match.name}</div>
                                <div class="match-question main">"${match.question}"</div>
                                ${match.similar_questions && match.similar_questions.length ? `
                                    <br>
                                    <div class="similar-questions">
                                        <div class="match-question">Similar Questions</div>
                                            ${match.similar_questions.map(q => `
                                                   <div class="match-question similar"> - "${q.question}" <span class="similar-score">(${Math.round(q.similarity * 100)}%)</span></div>
                                            `).join('')}
                                    </div>
                                ` : ''}
                            </div>
                            <div class="suitability-score">
                                <div class="score-circle ${scoreClass}">
                                    ${similarity}%
                                </div>
                                <div class="score-label ${scoreClass}">${scoreLabel}</div>
                            </div>
                        </div>
                        <div class="match-content">
                            <div class="match-answer">${match.answer}</div>
                            <div class="match-tags">
                                ${match.tags.map(tag => `<span class="tag">${tag}</span>`).join('')}
                            </div>
                            <button id="copy-btn" class="copy-btn" data-answer="${match.answer.replace(/'/g, "\\'")}">
                                📋 Copy Answer
                            </button>
                        </div>
                    </div>
                `;
        }).join('');

        container.classList.add('show');
    }

    getScoreClass = (score) => {
        if (score >= 80) return 'excellent';
        if (score >= 60) return 'good';
        if (score >= 40) return 'moderate';
        return 'poor';
    }

    getScoreLabel = (score) =>{
        if (score >= 80) return 'Excellent';
        if (score >= 60) return 'Good';
        if (score >= 40) return 'Moderate';
        return 'Poor';
    }

    hideResults = () => {
        document.getElementById('matchesContainer').classList.remove('show');
        document.getElementById('noMatches').style.display = 'none';
    }

    clearQuestion = () => {
        document.getElementById('customerQuestion').value = '';
        document.getElementById('customerQuestionDisplay').classList.remove('show');
        this.hideResults();
    }

    handleCopyClick(event) {
        const button = event.target.closest('.copy-btn');
        if (button) {
            this.copyAnswer(button.dataset.answer);

            button.innerHTML = '✓ Copied!';
            setTimeout(() => {
                button.innerHTML = '📋 Copy Answer';
            }, 2000);
        }
    }

    copyAnswer(answerText) {
        // Fallback for browsers without clipboard API or insecure connections
        if (!navigator.clipboard) {
            return this.useFallbackCopy(answerText);
        }

        navigator.clipboard.writeText(answerText)
            .then(() => {}).catch(err => {
                console.error('Clipboard API failed, using fallback:', err);
                this.useFallbackCopy(answerText);
            });
    }

    useFallbackCopy = (text) => {
        try {
            const textarea = document.createElement('textarea');
            textarea.value = text;
            textarea.style.position = 'fixed'; // Prevent scrolling
            document.body.appendChild(textarea);
            textarea.select();

            const successful = document.execCommand('copy');
            document.body.removeChild(textarea);
        } catch (err) {
            console.error('Fallback copy failed:', err);
            this.showStatus('Copy failed. Please select and copy manually.', 'error');
        }
    }

    handleEnterKeyPress = (event) => {
        const input = event.target;
        if (!input.classList.contains('question-input')) return;

        // Only trigger on an Enter key
        if (event.key === 'Enter') {
            event.preventDefault();
            this.askQuestion()
        }
    }
}