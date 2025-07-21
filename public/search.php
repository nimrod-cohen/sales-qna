<?php
$dir = SalesQnA::get_option('text_direction', 'ltr');
?>
<div class="sales-qna-search-page" style="direction:<?php echo esc_attr($dir); ?>;text-align:<?php echo($dir === 'rtl' ? 'right' : 'left'); ?>;">
    <div class="qna-search-container">
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
                    <span>
                        <i class="fa fa-search"></i>
                         Find Matches
                    </span>
                </button>
                <button id="clear-question" class="qna-button secondary">Clear</button>
            </div>
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
            <div class="no-matches-icon">
                <i class="fa fa-frown-o"></i>
            </div>
            <h3>No Matches Found</h3>
            <p>Try rephrasing the question or check for typos</p>
        </div>
    </div>
</div>

