<?php
declare(strict_types=1);
session_start();

function e(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function field(string $key, string $label, int $maxLength = 160): string
{
    $text = trim((string)($_POST[$key] ?? ''));
    if ($text === '' || strlen($text) > $maxLength) {
        throw new RuntimeException("$label không được để trống hoặc vượt quá $maxLength ký tự (byte).");
    }
    return $text;
}

function optionalField(string $key, string $label, int $maxLength = 255): ?string
{
    $text = trim((string)($_POST[$key] ?? ''));
    if (strlen($text) > $maxLength) {
        throw new RuntimeException("$label quá dài.");
    }
    return $text === '' ? null : $text;
}

function formId(string $key): int
{
    $value = filter_var($_POST[$key] ?? null, FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1],
    ]);
    if ($value === false) {
        throw new RuntimeException('Vui lòng chọn dữ liệu hợp lệ.');
    }
    return (int)$value;
}

function formDate(string $key, string $label): string
{
    $raw = (string)($_POST[$key] ?? '');
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $raw);
    if (!$date || $date->format('Y-m-d') !== $raw) {
        throw new RuntimeException("$label không hợp lệ.");
    }
    return $raw;
}

function meetingTime(): string
{
    $raw = (string)($_POST['starts_at'] ?? '');
    $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $raw);
    if (!$date || $date->format('Y-m-d\TH:i') !== $raw) {
        throw new RuntimeException('Ngày giờ họp không hợp lệ.');
    }
    return $date->format('Y-m-d H:i:s');
}

function emailField(): ?string
{
    $email = optionalField('email', 'Email', 150);
    if ($email !== null && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        throw new RuntimeException('Email không hợp lệ.');
    }
    return $email;
}

function jump(string $anchor): never
{
    header('Location: /#' . $anchor, true, 303);
    exit;
}

if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

try {
    $pdo = new PDO(
        'mysql:host=' . getenv('DB_HOST') . ';dbname=' . getenv('DB_NAME') . ';charset=utf8mb4',
        (string)getenv('DB_USER'),
        (string)getenv('DB_PASSWORD'),
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
} catch (PDOException $ex) {
    error_log($ex->getMessage());
    http_response_code(500);
    exit('Không kết nối được cơ sở dữ liệu CLB. Hãy kiểm tra Docker logs.');
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    $anchor = match ($action) {
        'add_member', 'edit_member', 'delete_member' => 'members',
        'add_activity', 'join_activity' => 'activities',
        'add_contribution' => 'contributions',
        'add_meeting' => 'meetings',
        default => 'overview',
    };
    try {
        if (!hash_equals((string)$_SESSION['csrf_token'], (string)($_POST['csrf_token'] ?? ''))) {
            throw new RuntimeException('Phiên làm việc không hợp lệ, tải lại trang rồi thử lại.');
        }

        switch ($action) {
            case 'add_member':
                $code = field('member_code', 'Mã thành viên', 30);
                $name = field('full_name', 'Họ tên', 120);
                $email = emailField();
                $phone = optionalField('phone', 'Số điện thoại', 20);
                $joined = formDate('joined_on', 'Ngày tham gia');
                $stmt = $pdo->prepare(
                    'INSERT INTO members (member_code, full_name, email, phone, joined_on)
                     VALUES (?, ?, ?, ?, ?)'
                );
                $stmt->execute([$code, $name, $email, $phone, $joined]);
                $message = 'Thêm thành viên thành công.';
                break;

            case 'edit_member':
                $memberId = formId('member_id');
                $name = field('full_name', 'Họ tên', 120);
                $email = emailField();
                $phone = optionalField('phone', 'Số điện thoại', 20);
                $status = (string)($_POST['status'] ?? '');
                if (!in_array($status, ['ACTIVE', 'INACTIVE'], true)) {
                    throw new RuntimeException('Trạng thái thành viên không hợp lệ.');
                }
                $stmt = $pdo->prepare(
                    'UPDATE members SET full_name=?, email=?, phone=?, status=? WHERE id=?'
                );
                $stmt->execute([$name, $email, $phone, $status, $memberId]);
                $message = 'Đã cập nhật thành viên.';
                break;

            case 'delete_member':
                $memberId = formId('member_id');
                // Database RESTRICT prevents removing members with contributions/attendance history.
                $stmt = $pdo->prepare('DELETE FROM members WHERE id=?');
                $stmt->execute([$memberId]);
                $message = 'Đã xóa thành viên chưa có lịch sử liên quan.';
                break;

            case 'add_activity':
                $title = field('title', 'Tên hoạt động');
                $date = formDate('activity_date', 'Ngày hoạt động');
                $location = field('location', 'Địa điểm');
                $description = optionalField('description', 'Mô tả', 3000);
                $stmt = $pdo->prepare(
                    'INSERT INTO activities (title, activity_date, location, description) VALUES (?, ?, ?, ?)'
                );
                $stmt->execute([$title, $date, $location, $description]);
                $message = 'Đã tạo hoạt động CLB.';
                break;

            case 'join_activity':
                $activityId = formId('activity_id');
                $memberId = formId('member_id');
                $check = $pdo->prepare("SELECT id FROM members WHERE id=? AND status='ACTIVE'");
                $check->execute([$memberId]);
                if ($check->fetchColumn() === false) {
                    throw new RuntimeException('Chỉ thành viên đang hoạt động mới được đăng ký.');
                }
                $stmt = $pdo->prepare(
                    'INSERT INTO activity_participants (activity_id, member_id) VALUES (?, ?)'
                );
                $stmt->execute([$activityId, $memberId]);
                $message = 'Đăng ký tham gia hoạt động thành công.';
                break;

            case 'add_contribution':
                $memberId = formId('member_id');
                $amount = filter_var($_POST['amount'] ?? null, FILTER_VALIDATE_INT, [
                    'options' => ['min_range' => 1, 'max_range' => 1000000000],
                ]);
                if ($amount === false) {
                    throw new RuntimeException('Số tiền phải là số nguyên VND lớn hơn 0.');
                }
                $date = formDate('contributed_on', 'Ngày đóng góp');
                $note = optionalField('note', 'Nội dung', 255);
                $stmt = $pdo->prepare(
                    'INSERT INTO contributions (member_id, amount, contributed_on, note) VALUES (?, ?, ?, ?)'
                );
                $stmt->execute([$memberId, $amount, $date, $note]);
                $message = 'Ghi nhận đóng góp thành công.';
                break;

            case 'add_meeting':
                $title = field('title', 'Tên cuộc họp');
                $startsAt = meetingTime();
                $location = field('location', 'Địa điểm');
                $agenda = optionalField('agenda', 'Nội dung cuộc họp', 3000);
                $stmt = $pdo->prepare(
                    'INSERT INTO meetings (title, starts_at, location, agenda) VALUES (?, ?, ?, ?)'
                );
                $stmt->execute([$title, $startsAt, $location, $agenda]);
                $message = 'Đã lên lịch họp CLB.';
                break;

            default:
                throw new RuntimeException('Thao tác không được hỗ trợ.');
        }

        $_SESSION['flash'] = ['type' => 'success', 'text' => $message];
    } catch (Throwable $ex) {
        if ($ex instanceof PDOException) {
            error_log($ex->getMessage());
            $message = $ex->getCode() === '23000'
                ? 'Dữ liệu bị trùng, không tồn tại hoặc đang được tham chiếu. Không thể thực hiện thao tác.'
                : 'Lỗi lưu dữ liệu. Hãy xem docker compose logs web.';
        } elseif ($ex instanceof RuntimeException) {
            $message = $ex->getMessage();
        } else {
            error_log($ex->getMessage());
            $message = 'Có lỗi không mong muốn khi xử lý.';
        }
        $_SESSION['flash'] = ['type' => 'error', 'text' => $message];
    }
    jump($anchor);
}

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

try {
    $stats = [
        'Thành viên' => (int)$pdo->query('SELECT COUNT(*) FROM members')->fetchColumn(),
        'Hoạt động' => (int)$pdo->query('SELECT COUNT(*) FROM activities')->fetchColumn(),
        'Tổng đóng góp (VNĐ)' => (float)$pdo->query('SELECT COALESCE(SUM(amount), 0) FROM contributions')->fetchColumn(),
        'Lịch họp' => (int)$pdo->query('SELECT COUNT(*) FROM meetings')->fetchColumn(),
    ];
    $members = $pdo->query('SELECT * FROM members ORDER BY id DESC')->fetchAll();
    $activeMembers = array_values(array_filter($members, fn($m) => $m['status'] === 'ACTIVE'));
    $activities = $pdo->query(
        'SELECT a.*, COUNT(ap.member_id) AS participant_count
         FROM activities a LEFT JOIN activity_participants ap ON a.id=ap.activity_id
         GROUP BY a.id ORDER BY a.activity_date DESC, a.id DESC'
    )->fetchAll();
    $participants = $pdo->query(
        'SELECT a.title, m.full_name, ap.registered_at
         FROM activity_participants ap
         JOIN activities a ON a.id=ap.activity_id
         JOIN members m ON m.id=ap.member_id
         ORDER BY ap.registered_at DESC, a.id DESC LIMIT 12'
    )->fetchAll();
    $contributions = $pdo->query(
        'SELECT c.amount, c.contributed_on, c.note, m.member_code, m.full_name
         FROM contributions c JOIN members m ON m.id=c.member_id
         ORDER BY c.id DESC LIMIT 20'
    )->fetchAll();
    $meetings = $pdo->query('SELECT * FROM meetings ORDER BY starts_at DESC, id DESC')->fetchAll();
} catch (PDOException $ex) {
    error_log($ex->getMessage());
    http_response_code(500);
    exit('Không thể đọc dữ liệu CLB. Hãy kiểm tra bảng dữ liệu MySQL.');
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Club Management System | Đề 39</title>
<style>
*{box-sizing:border-box}html{scroll-behavior:smooth}body{margin:0;background:#f3f6fb;color:#18263a;font-family:Arial,Helvetica,sans-serif;line-height:1.5}
header{background:#152a4a;color:white;padding:22px 7%}header h2{margin:0;font-size:23px}header p{margin:6px 0;color:#dbeafe}
nav{display:flex;flex-wrap:wrap;gap:18px;margin-top:16px}nav a{color:white;font-weight:bold;font-size:14px;text-decoration:none}nav a:hover{text-decoration:underline}
main{max-width:1250px;margin:30px auto;padding:0 20px}h1{font-size:26px}h2{font-size:21px;margin:0 0 16px}h3{font-size:17px;margin:0 0 12px}
.subtitle{color:#64748b}.cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:18px;margin:22px 0 30px}
.card,.panel{background:#fff;border-radius:12px;padding:23px;box-shadow:0 3px 15px #0000000c}.card{border-left:5px solid #2563eb}
.card h3{font-size:14px;color:#64748b;margin:0 0 7px}.card strong{font-size:29px;color:#1d4ed8}
.panel{margin:0 0 24px;overflow-x:auto}.two-col{display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:20px}
label{display:block;font-size:13px;font-weight:700;margin:10px 0 5px;color:#475569}
input,select,textarea{display:block;width:100%;border:1px solid #cbd5e1;border-radius:7px;padding:10px 12px;color:#18263a;font:inherit;background:white}
textarea{min-height:70px;resize:vertical}button{border:0;border-radius:7px;background:#2563eb;color:#fff;font-weight:700;padding:11px 15px;cursor:pointer;margin-top:11px}
button:hover{background:#1d4ed8}button.danger{background:#b91c1c}button.small{padding:7px 12px;font-size:12px}
button.danger:hover{background:#991b1b}table{border-collapse:collapse;width:100%;min-width:650px}
th,td{text-align:left;padding:11px 12px;border-bottom:1px solid #e2e8f0;vertical-align:top}th{background:#eff6ff;color:#1e3a5f}tbody tr:hover{background:#f8fafc}
.tag{font-size:12px;font-weight:700;padding:4px 8px;border-radius:30px;display:inline-block}.active{background:#dcfce7;color:#166534}.inactive{background:#fee2e2;color:#991b1b}
.alert{padding:14px 18px;border-radius:9px;margin-bottom:20px;font-weight:700}.success{background:#dcfce7;color:#14532d}.error{background:#fee2e2;color:#991b1b}
.muted{color:#64748b;font-size:13px}.inline-forms{display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap}.inline-forms>div{flex:1;min-width:180px}
details{margin-top:6px}summary{color:#1d4ed8;cursor:pointer;font-weight:700;font-size:13px}.edit-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:8px}
footer{text-align:center;color:#64748b;padding:20px;font-size:13px}
@media(max-width:640px){header{padding:22px}main{padding:0 12px}.card,.panel{padding:17px}nav{gap:12px}}
</style>
</head>
<body>
<header>
  <h2>🎓 CLUB MANAGEMENT SYSTEM</h2>
  <p>Đề 39 · Hệ thống quản lý Câu lạc bộ</p>
  <nav>
    <a href="#overview">Tổng quan</a><a href="#members">Thành viên</a><a href="#activities">Hoạt động</a>
    <a href="#contributions">Đóng góp</a><a href="#meetings">Lịch họp</a>
  </nav>
</header>
<main>
  <?php if ($flash): ?>
    <div class="alert <?= ($flash['type'] ?? '') === 'success' ? 'success' : 'error' ?>" role="status"><?= e($flash['text'] ?? '') ?></div>
  <?php endif; ?>
  <section id="overview">
    <h1>Dashboard tổng quan</h1>
    <p class="subtitle">Thống kê trực tiếp từ MySQL mỗi khi tải trang.</p>
    <div class="cards">
      <?php foreach ($stats as $label => $value): ?>
      <div class="card"><h3><?= e($label) ?></h3><strong><?= number_format((float)$value, 0, ',', '.') ?></strong></div>
      <?php endforeach; ?>
    </div>
  </section>

  <section id="members" class="panel">
    <h2>Quản lý thành viên</h2>
    <div class="two-col">
      <div>
        <h3>Thêm thành viên</h3>
        <form method="post">
          <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
          <input type="hidden" name="action" value="add_member">
          <label>Mã thành viên *</label><input name="member_code" maxlength="30" required placeholder="TV007">
          <label>Họ tên *</label><input name="full_name" maxlength="120" required placeholder="Nguyễn Văn A">
          <label>Email</label><input type="email" name="email" maxlength="150">
          <label>Số điện thoại</label><input name="phone" maxlength="20">
          <label>Ngày tham gia *</label><input type="date" name="joined_on" value="<?= date('Y-m-d') ?>" required>
          <button>Thêm thành viên</button>
        </form>
      </div>
      <div>
        <h3>Danh sách thành viên</h3>
        <p class="muted">Có <?= count($members) ?> thành viên. Có thể sửa thông tin, đổi trạng thái hoặc xóa thành viên chưa phát sinh lịch sử.</p>
        <div style="overflow-x:auto">
          <table>
            <thead><tr><th>Mã / Họ tên</th><th>Liên hệ</th><th>Trạng thái</th><th>Thao tác</th></tr></thead>
            <tbody>
            <?php foreach ($members as $m): ?>
              <tr>
                <td><strong><?= e($m['member_code']) ?></strong><br><?= e($m['full_name']) ?></td>
                <td><?= e($m['email'] ?: '—') ?><br><?= e($m['phone'] ?: '—') ?></td>
                <td><span class="tag <?= $m['status'] === 'ACTIVE' ? 'active' : 'inactive' ?>"><?= $m['status'] === 'ACTIVE' ? 'Hoạt động' : 'Tạm nghỉ' ?></span></td>
                <td>
                  <details><summary>Sửa / Xóa</summary>
                    <form method="post">
                      <input type="hidden" name="action" value="edit_member">
                      <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                      <input type="hidden" name="member_id" value="<?= e($m['id']) ?>">
                      <label>Họ tên</label><input name="full_name" required maxlength="120" value="<?= e($m['full_name']) ?>">
                      <label>Email</label><input name="email" type="email" maxlength="150" value="<?= e($m['email']) ?>">
                      <label>SĐT</label><input name="phone" maxlength="20" value="<?= e($m['phone']) ?>">
                      <label>Trạng thái</label><select name="status"><option value="ACTIVE" <?= $m['status'] === 'ACTIVE' ? 'selected' : '' ?>>Hoạt động</option><option value="INACTIVE" <?= $m['status'] === 'INACTIVE' ? 'selected' : '' ?>>Tạm nghỉ</option></select>
                      <button class="small">Lưu thay đổi</button>
                    </form>
                    <form method="post" onsubmit="return confirm('Chỉ xóa nếu thành viên không có đóng góp hoặc tham gia hoạt động. Xác nhận?')">
                      <input type="hidden" name="action" value="delete_member">
                      <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                      <input type="hidden" name="member_id" value="<?= e($m['id']) ?>">
                      <button class="danger small">Xóa</button>
                    </form>
                  </details>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </section>

  <section id="activities" class="panel">
    <h2>Hoạt động câu lạc bộ</h2>
    <div class="two-col">
      <div>
        <h3>Tạo hoạt động mới</h3>
        <form method="post">
          <input type="hidden" name="action" value="add_activity"><input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
          <label>Tên hoạt động *</label><input name="title" maxlength="160" required placeholder="Workshop An toàn thông tin">
          <label>Ngày diễn ra *</label><input type="date" name="activity_date" required>
          <label>Địa điểm *</label><input name="location" maxlength="160" required>
          <label>Mô tả</label><textarea name="description"></textarea>
          <button>Tạo hoạt động</button>
        </form>
      </div>
      <div>
        <h3>Đăng ký tham gia</h3>
        <form method="post">
          <input type="hidden" name="action" value="join_activity"><input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
          <label>Hoạt động *</label><select name="activity_id" required><option value="">-- Chọn hoạt động --</option><?php foreach ($activities as $a): ?><option value="<?= e($a['id']) ?>"><?= e($a['title']) ?></option><?php endforeach; ?></select>
          <label>Thành viên đang hoạt động *</label><select name="member_id" required><option value="">-- Chọn thành viên --</option><?php foreach ($activeMembers as $m): ?><option value="<?= e($m['id']) ?>"><?= e($m['member_code'] . ' - ' . $m['full_name']) ?></option><?php endforeach; ?></select>
          <button>Đăng ký tham gia</button>
          <p class="muted">Mỗi thành viên chỉ được đăng ký một lần cho cùng hoạt động.</p>
        </form>
      </div>
    </div>
    <h3 style="margin-top:26px">Danh sách hoạt động</h3>
    <table><thead><tr><th>Hoạt động</th><th>Ngày</th><th>Địa điểm</th><th>Người tham gia</th></tr></thead><tbody>
    <?php foreach ($activities as $a): ?>
      <tr><td><strong><?= e($a['title']) ?></strong><br><span class="muted"><?= e($a['description'] ?: '') ?></span></td><td><?= e($a['activity_date']) ?></td><td><?= e($a['location']) ?></td><td><?= e($a['participant_count']) ?></td></tr>
    <?php endforeach; ?>
    </tbody></table>
    <h3 style="margin-top:24px">Đăng ký tham gia gần đây</h3>
    <table><thead><tr><th>Thành viên</th><th>Hoạt động</th><th>Thời điểm ghi nhận</th></tr></thead><tbody>
      <?php foreach ($participants as $p): ?><tr><td><?= e($p['full_name']) ?></td><td><?= e($p['title']) ?></td><td><?= e($p['registered_at']) ?></td></tr><?php endforeach; ?>
    </tbody></table>
  </section>

  <section id="contributions" class="panel">
    <h2>Quản lý đóng góp</h2>
    <div class="two-col">
      <div>
        <h3>Ghi nhận khoản đóng góp</h3>
        <form method="post">
          <input type="hidden" name="action" value="add_contribution"><input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
          <label>Thành viên *</label><select name="member_id" required><option value="">-- Chọn thành viên --</option><?php foreach ($members as $m): ?><option value="<?= e($m['id']) ?>"><?= e($m['member_code'] . ' - ' . $m['full_name']) ?></option><?php endforeach; ?></select>
          <label>Số tiền (VND) *</label><input type="number" name="amount" min="1" max="1000000000" step="1" required placeholder="100000">
          <label>Ngày đóng góp *</label><input type="date" name="contributed_on" value="<?= date('Y-m-d') ?>" required>
          <label>Nội dung</label><input name="note" maxlength="255" placeholder="Quỹ CLB">
          <button>Ghi nhận đóng góp</button>
        </form>
      </div>
      <div>
        <h3>20 khoản đóng góp gần nhất</h3>
        <table><thead><tr><th>Ngày</th><th>Thành viên</th><th>Số tiền</th><th>Ghi chú</th></tr></thead><tbody>
        <?php foreach ($contributions as $c): ?>
          <tr><td><?= e($c['contributed_on']) ?></td><td><?= e($c['member_code'] . ' - ' . $c['full_name']) ?></td><td><strong><?= number_format((float)$c['amount'], 0, ',', '.') ?> đ</strong></td><td><?= e($c['note'] ?: '—') ?></td></tr>
        <?php endforeach; ?>
        </tbody></table>
      </div>
    </div>
  </section>

  <section id="meetings" class="panel">
    <h2>Quản lý lịch họp</h2>
    <div class="two-col">
      <div>
        <h3>Thêm lịch họp</h3>
        <form method="post">
          <input type="hidden" name="action" value="add_meeting"><input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
          <label>Tên cuộc họp *</label><input name="title" maxlength="160" required>
          <label>Ngày và giờ *</label><input type="datetime-local" name="starts_at" required>
          <label>Địa điểm *</label><input name="location" maxlength="160" required>
          <label>Nội dung</label><textarea name="agenda"></textarea>
          <button>Thêm lịch họp</button>
        </form>
      </div>
      <div>
        <h3>Lịch họp CLB</h3>
        <table><thead><tr><th>Cuộc họp</th><th>Thời gian</th><th>Địa điểm</th></tr></thead><tbody>
        <?php foreach ($meetings as $mt): ?>
          <tr><td><strong><?= e($mt['title']) ?></strong><br><span class="muted"><?= e($mt['agenda'] ?: '') ?></span></td><td><?= e($mt['starts_at']) ?></td><td><?= e($mt['location']) ?></td></tr>
        <?php endforeach; ?>
        </tbody></table>
      </div>
    </div>
  </section>
</main>
<footer>Đề 39 · PHP + MySQL · Nginx · Prometheus/Grafana · Loki/Promtail · Docker Compose</footer>
</body>
</html>
