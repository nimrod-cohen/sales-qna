<?php
$plugin_version = SalesQnA::version();
$dir = SalesQnA::get_option('text_direction', 'ltr');
?>
<h1 class='sales-qna-header'>
  Sales Q&A Manager
  <span style="font-size: 14px; color: #888;">v<?php echo esc_html($plugin_version); ?></span>
</h1>
<ul class="nav-tab-wrapper">
    <li class="nav-tab" data-tab="qna-settings">Settings</li>
    <li class="nav-tab nav-tab-active" data-tab="qna-ask">Ask</li>
    <li class="nav-tab" data-tab="qna-questions">Questions</li>
    <li class="nav-tab" data-tab="qna-intents">Intents</li>
    <li class="nav-tab" data-tab="qna-panel">Panel</li>
</ul>
<div id="qna-settings" class="sales-qna-settings tab wrap">
  <form method="post" class="rtl-switch">
    <label class="switch-title">RTL:</label>
    <label class="switch">
      <input type="checkbox" name="text_direction" value="rtl" onchange="this.form.submit();"<?php echo checked($dir, 'rtl', false); ?>>
      <span class="slider"></span>
    </label>
    <input type="hidden" name="toggle_direction" value="1">
  </form>
  <form method="post" class="openai-settings" style="margin-top: 20px;">
    <label for="openai_api_key"><strong>OpenAI API Key:</strong></label>
    <input type="text" name="openai_api_key" id="openai_api_key" value="<?php echo esc_attr(SalesQnA::get_option('openai_api_key', '')); ?>" style="width: 100%;" />
    <button type="submit" class="button button-primary" style="margin-top: 10px;">Save API Key</button>
</form>
</div>
<div id="qna-panel">

</div>
<div id="qna-ask" class="sales-qna-ask tab wrap" style="direction:<?php echo esc_attr($dir); ?>;text-align:<?php echo($dir === 'rtl' ? 'right' : 'left'); ?>;">
    <div class='qna-search-bar'>
        <input type="text" id="ask-input" placeholder="Ask your question" style="flex-grow:1">
        <button id="ask-question" class="button button-primary">Ask</button>
    </div>
    <table class="qna-answers-table widefat fixed">
        <colgroup>
            <col style="width: 25.00%;">
            <col style="width: 25.00%;">
            <col style="width: 40.00%;">
            <col style="width: 10.00%;">
        </colgroup>
        <thead>
        <tr>
            <th>Question</th>
            <th>Intent</th>
            <th>Answer</th>
            <th>Similarity</th>
        </tr>
        </thead>
        <tbody>
        <tr class="no-data">
            <td colspan="3">No answers were found.</td>
        </tr>
        </tbody>
    </table>
</div>
<div id="qna-questions" class="sales-qna-questions tab wrap" style="direction:<?php echo esc_attr($dir); ?>;text-align:<?php echo($dir === 'rtl' ? 'right' : 'left'); ?>;">
  <div class='qna-search-bar'>
    <input type="text" id="filter-questions" placeholder="🔍 חפש שאלה..." style="flex-grow:1">
    <button id="add-question" class="button button-primary">Add Question</button>
  </div>
  <table class="qna-table widefat fixed">
    <colgroup>
      <col style="width: 33.33%;">
      <col style="width: 66.67%;">
      <col style="width: 80px;">
    </colgroup>
    <thead>
      <tr>
          <th>Intent</th>
          <th>Question</th>
        <th></th>
      </tr>
    </thead>
    <tbody>
      <tr class="no-data">
        <td colspan="3">No questions found.</td>
      </tr>
    </tbody>
  </table>
</div>
<div id="qna-intents" class="sales-qna-intents tab wrap">
    <button id="add-intent" class="button button-primary mb-2">Add Intent</button>
    <table class="qna-intends-table widefat fixed">
        <colgroup>
            <col style="width: 33.33%;">
            <col style="width: 66.67%;">
            <col style="width: 80px;">
        </colgroup>
        <thead>
        <tr>
            <th>Intent</th>
            <th>Answer</th>
            <th></th>
        </tr>
        </thead>
        <tbody>
        <tr class="no-data">
            <td colspan="3">No intents found.</td>
        </tr>
        </tbody>
    </table>
</div>
