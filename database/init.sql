-- De 39: Club Management System - MySQL 8.0
-- Automatically executed only when mysql_data is empty.
-- The MySQL image creates MYSQL_DATABASE before this script is executed.
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS members (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    member_code VARCHAR(30) NOT NULL UNIQUE,
    full_name VARCHAR(120) NOT NULL,
    email VARCHAR(150) DEFAULT NULL,
    phone VARCHAR(20) DEFAULT NULL,
    joined_on DATE NOT NULL,
    status ENUM('ACTIVE', 'INACTIVE') NOT NULL DEFAULT 'ACTIVE',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS activities (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(160) NOT NULL,
    activity_date DATE NOT NULL,
    location VARCHAR(160) NOT NULL,
    description TEXT,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS activity_participants (
    activity_id INT UNSIGNED NOT NULL,
    member_id INT UNSIGNED NOT NULL,
    registered_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (activity_id, member_id),
    CONSTRAINT fk_participant_activity FOREIGN KEY (activity_id)
        REFERENCES activities(id) ON DELETE CASCADE,
    CONSTRAINT fk_participant_member FOREIGN KEY (member_id)
        REFERENCES members(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS contributions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    member_id INT UNSIGNED NOT NULL,
    amount DECIMAL(14,2) NOT NULL,
    contributed_on DATE NOT NULL,
    note VARCHAR(255) DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_contributions_member (member_id),
    CONSTRAINT chk_contribution_amount CHECK (amount > 0),
    CONSTRAINT fk_contribution_member FOREIGN KEY (member_id)
        REFERENCES members(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS meetings (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(160) NOT NULL,
    starts_at DATETIME NOT NULL,
    location VARCHAR(160) NOT NULL,
    agenda TEXT,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_meeting_time (starts_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Fictional demonstration data; can be modified through the website.
INSERT INTO members (member_code, full_name, email, phone, joined_on, status) VALUES
('TV001', 'Nguyễn Minh Anh', 'minhanh@example.com', '0912000001', '2026-09-01', 'ACTIVE'),
('TV002', 'Trần Hoàng Nam', 'hoangnam@example.com', '0912000002', '2026-09-02', 'ACTIVE'),
('TV003', 'Lê Thu Hà', 'thuha@example.com', '0912000003', '2026-09-04', 'ACTIVE'),
('TV004', 'Phạm Gia Huy', 'giahuy@example.com', '0912000004', '2026-09-04', 'ACTIVE'),
('TV005', 'Đỗ Bảo Ngọc', 'baongoc@example.com', '0912000005', '2026-09-05', 'ACTIVE'),
('TV006', 'Vũ Đức Long', 'duclong@example.com', '0912000006', '2026-09-08', 'INACTIVE');

INSERT INTO activities (title, activity_date, location, description) VALUES
('Workshop Git và Docker', '2026-10-15', 'Phòng thực hành A2', 'Thực hành Git, Docker Compose và triển khai ứng dụng.'),
('Ngày hội tình nguyện', '2026-10-22', 'Khuôn viên trường', 'Hoạt động cộng đồng của câu lạc bộ.'),
('Cuộc thi lập trình nội bộ', '2026-11-05', 'Phòng máy B1', 'Luyện tập kỹ năng lập trình và làm việc nhóm.');

INSERT INTO activity_participants (activity_id, member_id) VALUES
(1, 1), (1, 2), (1, 3), (2, 2), (2, 4), (2, 5), (3, 1), (3, 4);

INSERT INTO contributions (member_id, amount, contributed_on, note) VALUES
(1, 200000.00, '2026-10-02', 'Quỹ sinh hoạt tháng 10'),
(2, 150000.00, '2026-10-03', 'Quỹ sinh hoạt tháng 10'),
(3, 250000.00, '2026-10-04', 'Ủng hộ tổ chức workshop'),
(4, 150000.00, '2026-10-05', 'Quỹ sinh hoạt tháng 10'),
(5, 200000.00, '2026-10-06', 'Hỗ trợ sự kiện tình nguyện');

INSERT INTO meetings (title, starts_at, location, agenda) VALUES
('Họp Ban chủ nhiệm', '2026-10-12 18:00:00', 'Phòng 305', 'Phân công nhiệm vụ cho các thành viên.'),
('Họp kế hoạch Workshop', '2026-10-18 19:00:00', 'Phòng 204', 'Thống nhất nội dung và hậu cần workshop.'),
('Tổng kết hoạt động tháng', '2026-10-30 18:30:00', 'Phòng 305', 'Đánh giá hoạt động và công khai thu chi.');
