<?php
/** Settings → FAQs: questions and answers of the public FAQs page, and its help email. */
require __DIR__ . '/app/bootstrap.php';
require_role('admin');

$load = function (int $id): ?array {
    $st = db()->prepare('SELECT * FROM site_faqs WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
};

if (is_post()) {
    verify_csrf();
    $action = input('action');
    $id = (int) int_input('id', 0);
    $faq = $id ? $load($id) : null;

    if ($action === 'texts') {
        $errors = home_save_texts(home_text_keys('faq'), 'FAQs page email');
        foreach ($errors as $err) flash('error', $err);
        if (!$errors) flash('success', 'Help email saved.');
    } elseif ($action === 'toggle' && $faq) {
        db()->prepare('UPDATE site_faqs SET is_active = 1 - is_active WHERE id = ?')->execute([$id]);
        flash('success', $faq['is_active'] ? 'Question hidden from the FAQs page.' : 'Question shown on the FAQs page.');
    } elseif ($action === 'delete' && $faq) {
        db()->prepare('DELETE FROM site_faqs WHERE id = ?')->execute([$id]);
        log_activity('delete', 'settings', null, 'Deleted FAQ: ' . $faq['question']);
        flash('success', 'Question deleted.');
    } elseif ($action === 'save') {
        $q = mb_substr(trim((string) input('question')), 0, 300);
        $a = mb_substr(trim((string) input('answer')), 0, 5000);
        $order = (int) int_input('sort_order', 0);
        if ($q === '' || $a === '') {
            flash('error', 'Enter both the question and the answer.');
            $_SESSION['_old'] = $_POST;
            redirect('settings-faqs', $faq ? ['edit' => $id] : ['new' => 1]);
        }
        if ($faq) {
            db()->prepare('UPDATE site_faqs SET question = ?, answer = ?, sort_order = ? WHERE id = ?')->execute([$q, $a, $order, $id]);
            log_activity('update', 'settings', null, "Updated FAQ: {$q}");
            flash('success', 'Question updated.');
        } else {
            if (!$order) $order = (int) db()->query('SELECT COALESCE(MAX(sort_order), 0) + 10 FROM site_faqs')->fetchColumn();
            db()->prepare('INSERT INTO site_faqs (question, answer, sort_order) VALUES (?,?,?)')->execute([$q, $a, $order]);
            log_activity('create', 'settings', null, "Added FAQ: {$q}");
            flash('success', 'Question added.');
        }
    }
    redirect('settings-faqs');
}

$faqs = db()->query('SELECT * FROM site_faqs ORDER BY sort_order, id')->fetchAll();
$editing = ($e = int_input('edit')) ? $load($e) : null;
$showForm = $editing || isset($_GET['new']);
$old = $_SESSION['_old'] ?? [];
unset($_SESSION['_old']);
$v = fn(string $k, $def = '') => $old[$k] ?? $editing[$k] ?? $def;

$pageTitle = 'Settings · FAQs';
require APP_ROOT . '/app/layout/header.php';
?>
<div class="mb-6 flex flex-col sm:flex-row sm:items-end justify-between gap-3">
  <div><h1 class="text-xl font-bold text-slate-900">Settings</h1><p class="text-sm text-slate-500">Questions and answers on the public FAQs page.</p></div>
  <a href="<?= e(url('faqs')) ?>" target="_blank" rel="noopener" class="btn-secondary"><?= icon('globe') ?> View FAQs page</a>
</div>
<?php $settingsTab = 'settings-faqs'; require APP_ROOT . '/app/layout/settings_tabs.php'; ?>

<div class="grid grid-cols-1 xl:grid-cols-3 gap-6">
  <div class="xl:col-span-2 space-y-4">
    <?php if ($showForm): ?>
      <form method="post" class="card space-y-4">
        <?= csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?= e($editing['id'] ?? '') ?>">
        <h2 class="card-title"><?= $editing ? 'Edit question' : 'Add a question' ?></h2>
        <div><label class="label" for="question">Question <span class="req">*</span></label><input class="input" id="question" name="question" maxlength="300" value="<?= e($v('question')) ?>" required></div>
        <div><label class="label" for="answer">Answer <span class="req">*</span></label><textarea class="input" id="answer" name="answer" rows="5" required><?= e($v('answer')) ?></textarea></div>
        <div class="w-40"><label class="label" for="sort_order">Display order</label><input class="input" id="sort_order" type="number" name="sort_order" value="<?= e($v('sort_order')) ?>" placeholder="Last"><span class="hint">Lower numbers come first.</span></div>
        <div class="pt-4 border-t border-slate-100 flex justify-end gap-2">
          <a href="<?= e(url('settings-faqs')) ?>" class="btn-secondary">Cancel</a>
          <button class="btn-primary"><?= $editing ? 'Save question' : 'Add question' ?></button>
        </div>
      </form>
    <?php endif; ?>

    <div class="card p-0 overflow-hidden">
      <div class="p-5 pb-3 flex items-center justify-between gap-3">
        <div><h2 class="card-title">Questions</h2><p class="card-subtitle"><?= count($faqs) ?> in total · shown in display order</p></div>
        <?php if (!$showForm): ?><a href="<?= e(url('settings-faqs', ['new' => 1])) ?>" class="btn-primary btn-xs"><?= icon('plus', 'w-3.5 h-3.5') ?> Add question</a><?php endif; ?>
      </div>
      <ul class="divide-y divide-slate-100 border-t border-slate-100">
        <?php foreach ($faqs as $f): ?>
          <li class="p-4 flex items-start justify-between gap-4 <?= $f['is_active'] ? '' : 'bg-slate-50' ?>">
            <div class="min-w-0">
              <p class="font-semibold text-slate-900 text-sm"><span class="text-slate-400 font-mono text-xs mr-1"><?= (int) $f['sort_order'] ?></span> <?= e($f['question']) ?> <?= $f['is_active'] ? '' : status_badge('Inactive') ?></p>
              <p class="text-xs text-slate-500 mt-1 line-clamp-2"><?= e($f['answer']) ?></p>
            </div>
            <div class="flex items-center gap-1 shrink-0">
              <a href="<?= e(url('settings-faqs', ['edit' => $f['id']])) ?>" class="p-1.5 rounded-lg text-slate-500 hover:bg-slate-100" title="Edit"><?= icon('pencil') ?></a>
              <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= $f['id'] ?>"><button class="btn-secondary btn-xs"><?= $f['is_active'] ? 'Hide' : 'Show' ?></button></form>
              <form method="post" data-confirm="Delete this question?"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= $f['id'] ?>"><button class="p-1.5 rounded-lg text-rose-500 hover:bg-rose-50" title="Delete"><?= icon('trash') ?></button></form>
            </div>
          </li>
        <?php endforeach; ?>
        <?php if (!$faqs): ?><li class="p-8 text-center text-slate-400 text-sm">No questions yet.</li><?php endif; ?>
      </ul>
    </div>
  </div>

  <form method="post" class="card self-start">
    <?= csrf_field() ?><input type="hidden" name="action" value="texts">
    <h2 class="card-title mb-1">Help email</h2>
    <p class="card-subtitle mb-4">Shown as “Still have a question? Write to …” on the FAQs page.</p>
    <div class="grid grid-cols-1 gap-4"><?= home_text_fields(home_text_keys('faq')) ?></div>
    <div class="mt-5 flex justify-end"><button class="btn-primary">Save email</button></div>
  </form>
</div>
<?php require APP_ROOT . '/app/layout/footer.php';
