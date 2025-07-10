JSUtils.domReady(function () {
  const salesQnA = new SalesQnA();
  salesQnA.init();
});

class SalesQnA {
  debounceTimer = null;
  state = StateManagerFactory();

  init = () => {
    this.state.listen('questions', this.renderQuestionsTable);
    this.state.listen('intends', this.renderIntendsTable);
    this.state.listen('answers', this.renderAnswersTable);

    this.reloadQuestions();
    this.reloadIntends();

    this.state.listen('search', this.reloadQuestions);
    this.state.listen('show-add-question', this.showAddQuestionButton);
    this.state.set('show-add-question', true);

    JSUtils.addGlobalEventListener(document, '#ask-question', 'click', this.askQuestion);
    JSUtils.addGlobalEventListener(document, '#add-question', 'click', this.showAddQuestion);
    JSUtils.addGlobalEventListener(document, '#add-intent', 'click', this.showAddIntent);

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

  askQuestion = async () => {
    let question = document.getElementById('ask-input')?.value?.trim() || '';
    console.log(question);

    const res = await fetch('/wp-json/sales-qna/v1/answer', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        search: question || false
      })
    });
    if (!res.ok) {
      const error = await res.json();
      window.notifications.show(error.message, 'error');
    } else {
      const data = await res.json();

      this.state.set('answers', data);
    }
  };

  renderAnswersTable = data => {
    const tableBody = document.querySelector('table.qna-answers-table tbody');
    tableBody.innerHTML = '';

    data = data || [];

    if (data.length === 0) {
      tableBody.innerHTML = `<tr class="no-data">
          <td colspan="3">
            No answers were found. 
          </td>
        </tr>`;
      return;
    }

    data.forEach(item => {
      //round score to maximum 3 digits after the decimal point
      tableBody.insertAdjacentHTML(
          'beforeend',
          `<tr data-id="${item.id}" data-name="${item.intent_name}" data-answer="${item.intent_answer}">
           <td class='name'><span class='td-content'>${item.content}</span></td>
          <td class='name'><span class='td-content'>${item.intent_name}</span></td>
          <td>${item.intent_answer}</td>
          <td >
           ${ Math.round(item.similarity * 100)}%
          </td>
        </tr>`
      );
    });
  };

  showAddQuestion = () => {
    const question = document.getElementById('filter-questions')?.value?.trim() || '';
    if (question.trim().split(/\s+/).filter(Boolean).length <= 0) {
      window.notifications.show('Please enter a descriptive question', 'error');
      return;
    }

    this.showEditQuestion('', question, '');
  };

  editQuestionClicked = e => {
    e.stopPropagation();
    const row = e.target.closest('tr');
    const id = row.dataset.id;
    const question = row.dataset.question;
    const intentId = row.dataset.intentid;

    this.showEditQuestion(id,question, intentId);
  };

  showEditQuestion = async (id, question, intentId) => {
    const dir = document.querySelector('#qna-questions').style.direction || 'ltr';
    let intents = [];
    try {
      const response = await fetch('/wp-json/sales-qna/v1/intents/get');
      if (response.ok) {
        intents = await response.json();
      } else {
        console.error('Failed to fetch intents');
      }
    } catch (error) {
      console.error('Error fetching intents:', error);
    }

    // Generate dropdown options
    const intentOptions = intents.map(intent =>
        `<option value="${intent.id}" ${intent.id === intentId ? 'selected' : ''}>
            ${intent.name}
        </option>`
    ).join('');

    remodaler.show({
      type: remodaler.types.FORM,
      title: 'Edit Question',
      message: `<div class="modal-content" style="direction:${dir}">
        <form id="edit-question-form">
          <input type="hidden" name="id" value="${id}">
          <label for="qa-question">Question:</label>
          <input type="text" id="qa-question" name="question" disabled value="${question}" required>
          <label for="qa-intent">Intent:</label>
          <select id="qa-intent" name="intent_id" required>
            <option value="">Select an intent</option>
            ${intentOptions}
          </select>
        </form>`,
      confirmText: 'Save',
      confirm: async data => {
        if (!data.question || !data.intent_id) {
          window.notifications.show('Please fill in all fields.', 'error');
          return false;
        }

        data.id = id;
        const res = await fetch('/wp-json/sales-qna/v1/questions/save', {
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
        const res = await fetch('/wp-json/sales-qna/v1/questions/delete', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ id: id })
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
    const res = await fetch('/wp-json/sales-qna/v1/questions/get', {
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

  showAddIntent = () => {
    this.showEditIntent();
  }

  editIntentClicked = e => {
    e.stopPropagation();
    const row = e.target.closest('tr');
    const intent = row.dataset.intent;
    const answer = row.dataset.answer;
    this.showEditIntent(intent, answer);
  }

  showEditIntent = (intent, answer) => {
    const dir = document.querySelector('#qna-intents').style.direction || 'ltr';

    remodaler.show({
      type: remodaler.types.FORM,
      title: 'Edit Intent',
      message: `<div class="modal-content" style="direction:${dir}">
        <form id="edit-intent-form">
          <label for="qa-intent">Intent:</label>
          <input type="text" id="qa-intent" name="intent" value="${intent ?? null}" required>
          <label for="qa-answer">Answer:</label>
          <textarea id="qa-answer" name="answer" rows=10 required>${answer ?? null}</textarea>
        </form>`,
      confirmText: 'Save',
      confirm: async data => {
        if (!data.intent || !data.answer) {
          window.notifications.show('Please fill in all fields.', 'error');
          return false;
        }

        const res = await fetch('/wp-json/sales-qna/v1/intents/save', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify(data)
        });

        if (!res.ok) {
          const error = await res.json();
          window.notifications.show(error.message, 'error');
        }
        this.reloadIntents();
      }
    });
  };

  doDeleteIntent = e => {
    e.stopPropagation();
    const row = e.target.closest('tr');
    const id = row.dataset.id;

    remodaler.show({
      title: 'Attach/Detach User to affiliate',
      message: `<div class="modal-content">
        <h2>Delete Intent</h2>
        <p>Are you sure you want to delete this intent?</p>
      </div>`,
      type: remodaler.types.FORM,
      confirmText: 'Delete',
      confirm: async () => {
        const res = await fetch('/wp-json/sales-qna/v1/intents/delete', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ id: id })
        });

        if (!res.ok) {
          const error = await res.json();
          window.notifications.show(error.message, 'error');
        }
        this.reloadIntends();
      }
    });
  };

  reloadIntends = async () => {
    const res = await fetch('/wp-json/sales-qna/v1/intents/get', {
      method: 'GET'
    });
    if (!res.ok) {
      const error = await res.json();
    } else {
      const data = await res.json();
      this.state.set('intends', data);
    }
  }

  renderIntendsTable = data => {
    const tableBody = document.querySelector('table.qna-intends-table tbody');
    tableBody.innerHTML = '';

    data = data || [];

    if (data.length === 0) {
      tableBody.innerHTML = `<tr class="no-data">
          <td colspan="3">
            No questions found. 
          </td>
        </tr>`;
      return;
    }

    data.forEach(item => {
      //round score to maximum 3 digits after the decimal point
      tableBody.insertAdjacentHTML(
          'beforeend',
          `<tr data-id="${item.id}" data-intent="${item.name}" data-answer="${item.answer}">
          <td class='name'><span class='td-content'>${item.name}</span></td>
          <td>${item.answer}</td>
          <td class="qna-actions">
            <span class='td-content'>
              <span class="btn edit-btn dashicons dashicons-edit"></span>
              <span class="btn delete-btn dashicons dashicons-trash"></span>
            </span>
          </td>
        </tr>`
      );
    });

    document.querySelectorAll('table.qna-intends-table tbody tr .delete-btn').forEach(btn => {
      btn.addEventListener('click', this.doDeleteIntent);
    });

    document.querySelectorAll('table.qna-intends-table tbody tr .edit-btn').forEach(btn => {
      btn.addEventListener('click', this.editIntentClicked);
    });
  };

  renderQuestionsTable = data => {
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
    //this.state.set('show-add-question', data.length === 1 && data[0]?.is_fallback === true);
    data.forEach(item => {
      //round score to maximum 3 digits after the decimal point
      tableBody.insertAdjacentHTML(
        'beforeend',
        `<tr data-id="${item.id}" data-question="${item.question}" data-intent="${item.intent_name}" data-intentid="${item.intent_id}">
          <td class='question'><span class='td-content'>${item.intent_name}</span></td>
          <td>${item.question}</td>
          <td class="qna-actions">
            <span class='td-content'>
              <span class="btn edit-btn dashicons dashicons-edit"></span>
              <span class="btn delete-btn dashicons dashicons-trash"></span>
            </span>
          </td>
        </tr>`
      );

      const row = document.querySelector('tr[data-id="' + item.id + '"]');
      const search = this.state.get('search') || null;
      if (search) {
        row.querySelector('.question .td-content').innerHTML = this.highlightMatch(row.dataset.question, search);
        row.cells[1].innerHTML = this.highlightMatch(row.dataset.intent, search);
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
