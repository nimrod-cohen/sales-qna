<?php
$plugin_version = SalesQnA::version();
$dir = SalesQnA::get_option('text_direction', 'ltr');
?>
<div class="sales-qna-container">
    <div id="statusMessage" class="status-message"></div>

    <!-- Header -->
    <div class="sales-qna-header">
        <div class="header-title">
              <!-- Settings Trigger Button -->
          <button class="settings-trigger" id="open-settings-button" aria-label="Open Settings">
              <i class="fa-solid fa-gear"></i>
          </button>
          <h1>Sales Q&A</h1>
        </div>
        <p>Manage intents, answers, and questions</p>
    </div>
    <!-- Main Layout -->
    <div class="main-layout">
        <!-- Sidebar with Intent List -->
        <div class="sidebar">
            <div class="sales-qna-section">
                <h2 class="title">
                    <span class="icon">
                        <i class="fa-solid fa-comment"></i>
                    </span>
                    All Intents
                </h2>

                <!-- Search Box -->
                <div class="search-box">
                    <span class="search-icon">
                        <i class="fa-solid fa-magnifying-glass"></i>
                    </span>
                    <input type="text" class="search-input" id="intentSearch" placeholder="Search intents...">
                </div>

                <!-- Intent List -->
                <div class="intent-list" id="intentList">
                    <!-- Intents will be populated here -->
                </div>

                <!-- Add Intent Button -->
                <button id="create-new-intent" class="add-intent-btn">
                    <i class="fa-solid fa-plus"></i> Add New Intent
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

            <div class="sales-qna-section">
                <h2 class="title">
                    <span class="icon">
                        <i class="fa-solid fa-tags"></i>
                    </span>
                    Most Used Tags
                </h2>

                <!-- Tag Cloud Section -->
                <section class="tag-cloud-section">
                    <div class="tag-cloud-container">
                        <div class="tag-cloud-list" id="tagCloud">
                            <!-- Tags will be dynamically generated here -->
                        </div>
                    </div>
                    <div class="tag-cloud-stats">
                        <span class="stat">Total Tags: <span id="totalTags">0</span></span>
                        <span class="stat">Most Used: <span id="mostUsed">-</span></span>
                    </div>
                </section>
            </div>
        </div>



        <!-- Main Content Panel -->
        <div class="content-panel">
            <div class="sales-qna-section">
                <!-- Default Empty State -->
                <div id="emptyState" class="content-empty">
                    <div class="empty-state-icon">
                        <i class="fa-solid fa-comment"></i>
                    </div>
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
                                <i class="fa-solid fa-pen"></i> Edit
                            </button>
                            <button id="delete-intent" class="btn btn-danger btn-small">
                                <i class="fa-solid fa-trash"></i> Delete
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
                            <i class="fa-solid fa-floppy-disk"></i> Save Answer
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
                            <span class="section-icon">
                                <i class="fa-solid fa-question"></i>
                            </span>
                            Questions
                            <span style="font-size: 0.8rem; color: #64748b; margin-left: auto;" id="questionCounter"></span>
                        </h3>

                        <div id="questionsList" class="questions-list">
                            <!-- Questions will be populated here -->
                        </div>

                        <div id="add-new-question" class="add-question">
                            <div><i class="fa-solid fa-plus"></i> Add New Question</div>
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </div>
</div>

<!-- Custom Confirm Dialog -->
<div id="confirmOverlay" class="confirm-overlay <?php echo esc_attr($dir); ?>">
    <div class="confirm-dialog">
        <div class="confirm-header">
            <div id="confirmIcon" class="confirm-icon">
                <i class="fa-solid fa-trash"></i>
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

<!-- Settings Overlay -->
<div class="settings-overlay" id="settings-overlay"></div>

<!-- Settings Panel -->
<div class="settings-panel" id="settingsPanel">
    <div class="settings-header">
        <h2 class="settings-title">
            <i class="fa-solid fa-gear"></i>
            Settings
        </h2>
        <button class="close-btn" id="close-settings-button" aria-label="Close Settings">
            ✕
        </button>
    </div>

    <div class="settings-content">
        <!-- RTL Language Support -->
        <div class="setting-group">
            <label class="setting-label">Language Direction</label>
            <p class="setting-description">
                Enable right-to-left (RTL) support for Arabic, Hebrew, and other RTL languages.
            </p>
            <div class="toggle-container">
                <div class="toggle-switch" id="toggle-rtl-switch">
                    <div class="toggle-slider"></div>
                </div>
                <label class="toggle-label" id="toggle-rtl-label">
                    Enable RTL Support
                </label>
            </div>
        </div>

        <!-- OpenAI API Key -->
        <div class="setting-group">
            <label class="setting-label" for="apiKey">OpenAI API Key</label>
            <p class="setting-description">
                Enter your OpenAI API key to enable AI-powered features in the application.
            </p>
            <div class="input-container">
                <input
                        type="text"
                        id="apiKey"
                        class="form-input"
                        placeholder="sk-..."
                        autocomplete="off"
                >
            </div>
        </div>

        <!-- Admin Threshold Support -->
        <div class="setting-group">
            <label class="setting-label">Admin Threshold</label>
            <p class="setting-description">
                Choose the minimum similarity level required to filter intents in the intent search admin panel.
                (Admin panel may need reloading)
            </p>
            <div class="input-container">
                <input
                        type="text"
                        id="adminThreshold"
                        class="form-input"
                        placeholder="Similarity between 0.0 and 1.0"
                        autocomplete="off"
                >
            </div>
        </div>

        <!-- Shortcode Message -->
        <div class="setting-group">
            <label class="setting-label">Display Search Panel</label>
            <p class="setting-description">
                To add the Q&A search feature to any page, simply use this shortcode:
                <code>[sales_qna_search_page]</code>
            </p>
        </div>

        <!-- Export/Import Support -->
        <div class="setting-group">
            <label class="setting-label">Export & Import Q&A Intents</label>
            <p class="setting-description">
                Manage your question-and-answer knowledge base by exporting existing intents or importing new ones.
                This allows you to:<br><br>
                • Backup your current Q&A pairs<br>
                • Migrate intents between installations<br>
                • Bulk import new questions and answers<br><br>
            </p>
            <button id="sales-qna-export" class="btn btn-secondary">Export Q&A</button>
            <button id="sales-qna-import" class="btn btn-primary">Import Q&A</button>
            <input type="file" id="sales-qna-import-form" accept=".zip" style="display: none;" />
        </div>

        <!-- Save Button -->
        <!-- Settings Version -->
        <div class="setting-group settings-footer">
            <button class="btn btn-primary" id="save-settings-button">
                Save Settings
            </button>

            <p class="setting-description">Plugin Version: <?php echo esc_attr($plugin_version); ?></p>
        </div>
    </div>
</div>

<!-- Status Messages -->


