<?php
$plugin_version = SalesQnA::version();
$dir = SalesQnA::get_option('text_direction', 'ltr');
?>
<div class="sales-qna-container"  style="direction:<?php echo esc_attr($dir); ?>;text-align:<?php echo($dir === 'rtl' ? 'right' : 'left'); ?>;">
    <!-- Header -->
    <div class="sales-qna-header">
        <h1>Q&A Admin Panel</h1>
        <p>Manage intents, answers, and questions</p>
    </div>

    <!-- Status Messages -->
    <div id="statusMessage" class="status-message"></div>

    <!-- Main Layout -->
    <div class="main-layout">
        <!-- Sidebar with Intent List -->
        <div class="sidebar">
            <div class="sales-qna-section">
                <h2 class="title">
                    <span class="icon">🎯</span>
                    All Intents
                </h2>

                <!-- Search Box -->
                <div class="search-box">
                    <span class="search-icon">🔍</span>
                    <input type="text" class="search-input" id="intentSearch" placeholder="Search intents...">
                </div>

                <!-- Intent List -->
                <div class="intent-list" id="intentList">
                    <!-- Intents will be populated here -->
                </div>

                <!-- Add Intent Button -->
                <button id="create-new-intent" class="add-intent-btn">
                    ➕ Add New Intent
                </button>

                <!-- New Intent Form -->
                <div id="newIntentForm" class="new-intent-form">
                    <div class="form-group">
                        <label class="form-label" for="intent-input">Intent Name</label>
                        <input type="text" class="form-input intent-input" id="intent-input" placeholder="Enter intent name...">
                    </div>
                    <div class="form-actions">
                        <button id="save-new-intent" class="btn btn-primary">Create</button>
                        <button id="cancel-new-intent" class="btn btn-secondary">Cancel</button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Main Content Panel -->
        <div class="content-panel">
            <div class="sales-qna-section">
                <!-- Default Empty State -->
                <div id="emptyState" class="content-empty">
                    <div class="empty-state-icon">🎯</div>
                    <h3>Select an Intent</h3>
                    <p>Choose an intent from the list to manage its answer and questions</p>
                </div>

                <!-- Intent Management Content -->
                <div id="intentContent" style="display: none;">
                    <!-- Intent Header -->
                    <div class="intent-header">
                        <h2 class="intent-title" id="intentTitle">Intent Name</h2>
                        <div class="intent-actions">
                            <button id="edit-intent" class="btn btn-warning btn-small">
                                ✏️ Edit Name
                            </button>
                            <button id="delete-intent" class="btn btn-danger btn-small">
                                🗑️ Delete
                            </button>
                        </div>
                    </div>

                    <!-- Intent Edit Form -->
                    <div id="intentEditForm" class="intent-edit-form" style="display: none;">
                        <div class="form-group">
                            <label class="form-label" for="editIntentName">Intent Name</label>
                            <input type="text" class="form-input" id="editIntentName" placeholder="Enter intent name...">
                        </div>
                        <div class="form-actions">
                            <button id="save-edit-intent" class="btn btn-success">Save Changes</button>
                            <button id="cancel-edit-intent" class="btn btn-secondary">Cancel</button>
                        </div>
                    </div>

                    <!-- Answer Management -->
                    <div class="form-group">
                        <label class="form-label" for="answerText">Answer</label>
                        <textarea class="form-textarea" id="answerText" placeholder="Enter the answer for this intent..."></textarea>
                    </div>

                    <div class="form-actions">
                        <button id="save-answer" class="btn btn-success">
                            💾 Save Answer
                        </button>
                    </div>

                    <!-- Tags Section -->
                    <div class="sales-qna-tags-section">
                        <div id="tagsList" class="tags-list">
                            <!-- Questions will be populated here -->
                        </div>
                    </div>

                    <!-- Questions Section -->
                    <div class="sales-qna-questions-section">
                        <h3 class="section-title">
                            <span class="section-icon">❓</span>
                            Questions
                            <span style="font-size: 0.8rem; color: #64748b; margin-left: auto;" id="questionCounter"></span>
                        </h3>

                        <div id="questionsList" class="questions-list">
                            <!-- Questions will be populated here -->
                        </div>

                        <div id="add-new-question" class="add-question">
                            <div>➕ Add New Question</div>
                        </div>
                    </div>
                </div>
            </div>

        </div>

        <div class="sales-qna-settings">
            <div id="qna-settings-toggle" class="qna-settings-toggle">Settings</div>
            <div id="qna-settings-content" class="qna-hidden qna-settings-content sales-qna-section">
                <form method="post" class="rtl-switch">
                    <label class="switch-title">RTL:</label>
                    <label class="switch">
                        <input type="checkbox" name="text_direction" value="rtl"
                               onchange="this.form.submit();"<?php echo checked( $dir, 'rtl', false ); ?>>
                        <span class="slider"></span>
                    </label>
                    <input type="hidden" name="toggle_direction" value="1">
                </form>
                <form method="post" class="openai-settings" style="margin-top: 20px;">
                    <label for="openai_api_key"><strong>OpenAI API Key:</strong></label>
                    <input type="text" name="openai_api_key" id="openai_api_key"
                           value="<?php echo esc_attr( SalesQnA::get_option( 'openai_api_key', '' ) ); ?>"
                           style="width: 100%;"/>
                    <button type="submit" class="btn btn-success" style="margin-top: 10px;">Save API Key</button>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Custom Confirm Dialog -->
<div id="confirmOverlay" class="confirm-overlay" style="direction:<?php echo esc_attr($dir); ?>;text-align:<?php echo($dir === 'rtl' ? 'right' : 'left'); ?>;">
    <div class="confirm-dialog">
        <div class="confirm-header">
            <div id="confirmIcon" class="confirm-icon">
                🗑️
            </div>
            <h3 id="confirmTitle" class="confirm-title">Confirm Action</h3>
            <p id="confirmMessage" class="confirm-message">Are you sure you want to proceed?</p>
        </div>
        <div id="confirmDetails" class="confirm-details" style="display: none;">
            <!-- Details will be populated here -->
        </div>
        <div class="confirm-actions">
            <button id="confirmCancel" class="confirm-btn confirm-btn-cancel">Cancel</button>
            <button id="confirmAction" class="confirm-btn confirm-btn-danger">Delete</button>
        </div>
    </div>
</div>

