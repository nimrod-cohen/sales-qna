<?php
?>
<div class="sales-qna-search-page">
    <div class="qna-search-container">
        <!-- Header -->
        <div class="header">
            <h1>Question Matching Interface</h1>
            <p>Find the best answers for customer questions with suitability scoring</p>
        </div>

        <!-- Question Input -->
        <div class="question-input-section">
            <label class="input-label" for="customerQuestion">Customer Question</label>
            <textarea
                    id="customerQuestion"
                    class="question-input"
                    placeholder="Enter the customer's question here to find the best matching answers..."
                    rows="3"
            ></textarea>
            <div class="question-buttons">
                <button id="ask-question" class="qna-button primary">
                    <span>🔍 Find Matches</span>
                </button>
                <button id="clear-question" class="qna-button secondary">Clear</button>
            </div>
        </div>

        <!-- Customer Question Display -->
        <div id="customerQuestionDisplay" class="customer-question-display">
            <div class="customer-question-label">Customer Question</div>
            <div id="displayedQuestion" class="customer-question-text"></div>
        </div>

        <!-- Loading State -->
        <div id="loadingState" class="loading-state">
            <div class="loading-spinner"></div>
            <h3>Analyzing Question...</h3>
            <p>Finding the best matching answers</p>
        </div>

        <!-- Results -->
        <div id="matchesContainer" class="matches-container">
            <div class="results-header">
                <h2 class="results-title">Matching Answers</h2>
                <div id="resultsCount" class="results-count">0 matches found</div>
            </div>
            <div id="matchesList">
                <!-- Matches will be populated here -->
            </div>
        </div>

        <!-- No Matches State -->
        <div id="noMatches" class="no-matches" style="display: none;">
            <div class="no-matches-icon">🤔</div>
            <h3>No Matches Found</h3>
            <p>Try rephrasing the question or check for typos</p>
        </div>
    </div>
</div>

