JSUtils.domReady(function () {
  const salesQnA = new SalesQnA();
  salesQnA.init();
});

class SalesQnA {
  debounceTimer = null;
  state = StateManagerFactory();

  init = () => {
    this.state.listen('rows', this.renderTable);
    this.reloadQuestions();
    this.state.listen('search', this.reloadQuestions);

    document.getElementById('filter-input').addEventListener('input', this.updateSearch);

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
    this.showEditQuestion(null, '', '');
  };

  showEditQuestion = (id, question, answer) => {
    const dir = document.querySelector('#qna-questions').style.direction || 'ltr';

    remodaler.show({
      type: remodaler.types.FORM,
      title: 'Edit Question',
      message: `<div class="modal-content" style="direction:${dir}">
        <form id="edit-question-form">
          <input type="hidden" name="id" value="${id}">
          <label for="qa-question">Question:</label>
          <input type="text" id="qa-question" name="question" value="${question}" required>
          <label for="qa-answer">Answer:</label>
          <textarea id="qa-answer" name="answer" rows=10 required>${answer}</textarea>
        </form>`,
      confirmText: 'Save',
      confirm: async data => {
        if (!data.question || !data.answer) {
          alert('Please fill in all fields.');
          return false;
        }

        data.id = id;
        const res = await fetch('/wp-json/sales-qna/v1/save', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify(data)
        });

        if (!res.ok) {
          const error = await res.json();
          alert(`Error: ${error.message}`);
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
        <p>Are you sure you want to delete Q&A #${id}?</p>
      </div>`,
      type: remodaler.types.FORM,
      confirmText: 'Delete',
      confirm: async () => {
        const res = await fetch('/wp-json/sales-qna/v1/delete', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ id })
        });

        if (!res.ok) {
          const error = await res.json();
          alert(`Error: ${error.message}`);
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
      alert(`Error: ${error.message}`);
    } else {
      const data = await res.json();
      this.state.set('rows', data);
    }
  };

  renderTable = data => {
    const tableBody = document.querySelector('table tbody');
    tableBody.innerHTML = '';

    if (!data || data.length === 0) {
      tableBody.innerHTML = '<tr class="no-data"><td colspan="4">No questions found.</td></tr>';
      return;
    }

    data.forEach(item => {
      const row = document.createElement('tr');
      row.dataset.id = item.id;
      row.dataset.question = item.question;
      row.dataset.answer = item.answer;

      row.innerHTML = `
        <td>${item.id}</td>
        <td>${item.question}</td>
        <td>${item.answer}</td>
        <td class="qna-actions">
          <div>
            <span class="btn edit-btn dashicons dashicons-edit"></span>
            <span class="btn delete-btn dashicons dashicons-trash"></span>
          </div>
        </td>
      `;

      tableBody.appendChild(row);
      const search = this.state.get('search') || null;
      if (search) {
        row.cells[1].innerHTML = this.highlightMatch(row.dataset.question, search);
        row.cells[2].innerHTML = this.highlightMatch(row.dataset.answer, search);
      }
    });

    document.querySelectorAll('table tbody tr .delete-btn').forEach(btn => {
      btn.addEventListener('click', this.doDeleteQuestion);
    });

    document.querySelectorAll('table tbody tr .edit-btn').forEach(btn => {
      btn.addEventListener('click', this.editQuestionClicked);
    });
    document.querySelector('#add-question').addEventListener('click', this.showAddQuestion);
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
