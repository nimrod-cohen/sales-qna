JSUtils.domReady(function () {
  const salesQnA = new SalesQnA();
  salesQnA.init();
});

class SalesQnA {
  debounceTimer = null;
  state = StateManagerFactory();

  init = () => {
    this.state.listen('questions', this.renderTable);
    this.reloadQuestions();
    this.state.listen('search', this.reloadQuestions);
    this.state.listen('show-add-question', this.showAddQuestionButton);
    this.state.set('show-add-question', false);

    JSUtils.addGlobalEventListener(document, '#add-question', 'click', this.showAddQuestion);

    document.getElementById('filter-questions').addEventListener('input', this.updateSearch);

    document.querySelectorAll('.nav-tab').forEach(tab => {
      tab.addEventListener('click', e => {
        e.preventDefault();
        const target = tab.dataset.tab;

        // toggle tabs
        document.querySelectorAll('.nav-tab').forEach(t => t.classList.remove('nav-tab-active'));
        tab.classList.add('nav-tab-active');

        // toggle content
        document.querySelectorAll('.tab').forEach(content => {
          content.style.display = content.id === target ? 'block' : 'none';
        });
      });
    });

    const activeTab = document.querySelector('.nav-tab-active')?.dataset.tab;

    document.querySelectorAll('.tab').forEach(tab => {
      tab.style.display = tab.id === activeTab ? 'block' : 'none';
    });
  };

  showAddQuestionButton = show => {
    document.querySelector('#add-question').style.display = show ? 'block' : 'none';
  };

  updateSearch = e => {
    clearTimeout(this.debounceTimer);

    let oldTerm = this.state.get('search') || '';
    const term = e.target.value.trim();

    this.debounceTimer = setTimeout(() => {
      if (term === oldTerm) return;
      this.state.set('search', term);
    }, 800); // delay in milliseconds
  };

  editQuestionClicked = e => {
    e.stopPropagation();
    const row = e.target.closest('tr');
    const id = row.dataset.id;
    const question = row.dataset.question;
    const answer = row.dataset.answer;

    this.showEditQuestion(id, question, answer);
  };

  showAddQuestion = () => {
    const question = document.getElementById('filter-questions')?.value?.trim() || '';
    if (question.trim().split(/\s+/).filter(Boolean).length <= 3) {
      window.notifications.show('Please enter a descriptive question', 'error');
      return;
    }

    this.showEditQuestion('', question, '');
  };

  showEditQuestion = (intentId, question, answer) => {
    const dir = document.querySelector('#qna-questions').style.direction || 'ltr';

    remodaler.show({
      type: remodaler.types.FORM,
      title: 'Edit Question',
      message: `<div class="modal-content" style="direction:${dir}">
        <form id="edit-question-form">
          <input type="hidden" name="intent_id" value="${intentId}">
          <label for="qa-question">Question:</label>
          <input type="text" id="qa-question" name="question" disabled value="${question}" required>
          <label for="qa-answer">Answer:</label>
          <textarea id="qa-answer" name="answer" rows=10 required>${answer}</textarea>
        </form>`,
      confirmText: 'Save',
      confirm: async data => {
        if (!data.question || !data.answer) {
          window.notifications.show('Please fill in all fields.', 'error');
          return false;
        }

        data.intent_id = intentId;
        const res = await fetch('/wp-json/sales-qna/v1/save', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify(data)
        });

        if (!res.ok) {
          const error = await res.json();
          window.notifications.show(error.message, 'error');
        }
        this.reloadQuestions();
      }
    });
  };

  doDeleteQuestion = e => {
    e.stopPropagation();
    const row = e.target.closest('tr');
    const id = row.dataset.id;

    remodaler.show({
      title: 'Attach/Detach User to affiliate',
      message: `<div class="modal-content">
        <h2>Delete Question</h2>
        <p>Are you sure you want to delete this question?</p>
      </div>`,
      type: remodaler.types.FORM,
      confirmText: 'Delete',
      confirm: async () => {
        const res = await fetch('/wp-json/sales-qna/v1/delete', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ intent_id: id })
        });

        if (!res.ok) {
          const error = await res.json();
          window.notifications.show(error.message, 'error');
        }
        this.reloadQuestions();
      }
    });
  };

  reloadQuestions = async () => {
    const res = await fetch('/wp-json/sales-qna/v1/get', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        search: this.state.get('search') || false
      })
    });
    if (!res.ok) {
      const error = await res.json();
      window.notifications.show(error.message, 'error');
    } else {
      const data = await res.json();
      this.state.set('questions', data);
    }
  };

  renderTable = data => {
    const tableBody = document.querySelector('table.qna-table tbody');
    tableBody.innerHTML = '';

    data = data || [];

    if (data.length === 0) {
      tableBody.innerHTML = `<tr class="no-data">
          <td colspan="3">
            No questions found. 
          </td>
        </tr>`;
      this.state.set('show-add-question', true);
      return;
    }

    this.state.set('show-add-question', data.length === 1 && data[0]?.is_fallback === true);

    data.forEach(item => {
      //round score to maximum 3 digits after the decimal point
      tableBody.insertAdjacentHTML(
        'beforeend',
        `<tr data-id="${item.intent_id}" data-question="${item.question}" data-answer="${item.answer}">
          <td class='question'><span class='td-content'>${item.question}</span></td>
          <td>${item.answer}</td>
          <td class="qna-actions">
            <span class='td-content'>
              <span class="btn edit-btn dashicons dashicons-edit"></span>
              <span class="btn delete-btn dashicons dashicons-trash"></span>
            </span>
          </td>
        </tr>`
      );

      const row = document.querySelector('tr[data-id="' + item.intent_id + '"]');
      const search = this.state.get('search') || null;
      if (search) {
        row.querySelector('.question .td-content').innerHTML = this.highlightMatch(row.dataset.question, search);
        row.cells[1].innerHTML = this.highlightMatch(row.dataset.answer, search);
      }
      if (item.is_fallback === true) {
        row
          .querySelector('.question .td-content')
          .insertAdjacentHTML('beforeend', "<span title='fallback intent' class='fallback'></span>");
      }
    });

    document.querySelectorAll('table.qna-table tbody tr .delete-btn').forEach(btn => {
      btn.addEventListener('click', this.doDeleteQuestion);
    });

    document.querySelectorAll('table.qna-table tbody tr .edit-btn').forEach(btn => {
      btn.addEventListener('click', this.editQuestionClicked);
    });
  };

  highlightMatch = (text, term) => {
    if (!term) return this.escapeHtml(text);

    const regex = new RegExp(`(${term})`, 'gi');
    return this.escapeHtml(text).replace(regex, '<mark>$1</mark>');
  };

  escapeHtml = unsafe => {
    return unsafe
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  };
}
