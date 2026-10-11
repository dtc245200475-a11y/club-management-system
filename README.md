# Đề 39 – Club Management System (Quản lý Câu lạc bộ)

Website PHP + MySQL triển khai bằng Docker Compose trên **một máy ảo Ubuntu**. Demo tại chỗ, không yêu cầu triển khai lên máy chủ bên ngoài.

## 1. Nghiệp vụ

- **Thành viên:** thêm, xem, sửa thông tin/trạng thái; xóa thành viên chưa có lịch sử liên quan.
- **Hoạt động:** tạo hoạt động, thống kê số người tham gia, đăng ký thành viên đang hoạt động (không đăng ký trùng).
- **Đóng góp:** ghi nhận thành viên, số tiền VND, ngày, nội dung; thống kê tổng tiền và lịch sử.
- **Lịch họp:** thêm cuộc họp, ngày giờ, địa điểm và nội dung.
- **Dashboard:** 4 ô thống kê trực tiếp từ MySQL.

Database khởi tạo sẵn **6 thành viên, 3 hoạt động, 8 lượt tham gia, 5 khoản đóng góp và 3 lịch họp mẫu** để thử ngay.

## 2. Công nghệ và kiến trúc

```text
Trình duyệt --> Nginx :8080 --> PHP Apache :80 --> MySQL 8.0
                            \--> phpMyAdmin :8082 --> MySQL

Prometheus <-- Node Exporter (máy ảo Ubuntu)
           <-- cAdvisor (Docker container)
           <-- MySQL Exporter (MySQL)
           <-- Blackbox Exporter (HTTP Nginx)
Grafana --> Prometheus (dashboard), Loki (log)
Promtail --> Loki <-- Grafana Explore (LogQL)
```

Các dịch vụ trên đều nằm trong `compose.yml`, có hai mạng `app-network`, `monitoring`. Nginx/Prometheus/Grafana/phpMyAdmin/Loki chỉ mở trên `127.0.0.1` máy ảo. PHP không publish trực tiếp ra host.

## 3. Chạy trên máy ảo Ubuntu

**Yêu cầu:** Docker Engine cài qua APT, Docker Compose plugin (`docker compose version`), quyền dùng Docker, Docker logging driver `json-file` nếu dùng Promtail theo cấu hình kèm theo.

Mở VS Code tại thư mục `club-management-system`, chạy:

```bash
cp .env.example .env
```

Mở file `.env` bằng VS Code, đổi các mật khẩu mẫu sang mật khẩu riêng dễ nhớ. Giữ các tên biến và để `MYSQL_DATABASE=club_db`. **Không commit `.env` lên GitHub**.

```bash
docker compose config --quiet
docker compose up -d --build
docker compose ps
```

Đợt tải image đầu tiên có thể mất vài phút. Script `database/init.sql` và `database/02-create-exporter.sh` chỉ chạy khi MySQL volume mới/trống. **Không dùng `docker compose down -v` khi cần giữ dữ liệu**.

### Truy cập

| Dịch vụ | Địa chỉ trên Ubuntu |
|---|---|
| Website CLB | http://localhost:8080 |
| phpMyAdmin | http://localhost:8082 |
| Grafana | http://localhost:3000 |
| Prometheus | http://localhost:9090 |
| Loki readiness | http://localhost:3100/ready |

phpMyAdmin: user `root` + `MYSQL_ROOT_PASSWORD` trong `.env`, hoặc user `club_user` + `MYSQL_PASSWORD`. Grafana: user `admin` + `GRAFANA_ADMIN_PASSWORD` trong `.env`.

## 4. Demo chức năng website

1. Mở website CLB, kiểm tra 4 ô thống kê và danh sách thành viên.
2. Thêm thành viên `TV007`, sửa trạng thái thành `Tạm nghỉ`, sau đó đổi lại `Hoạt động`.
3. Tạo hoạt động **Workshop An toàn thông tin**, đăng ký `TV007`; thử đăng ký lần nữa phải bị từ chối.
4. Ghi nhận thành viên `TV007` đóng góp `100000` VND, kiểm tra tổng tiền tăng.
5. Tạo lịch họp mới, kiểm tra trong bảng lịch họp.
6. Trong phpMyAdmin chọn database `club_db` kiểm tra 5 bảng `members`, `activities`, `activity_participants`, `contributions`, `meetings`.

Ví dụ kiểm tra MySQL bằng Terminal:

```bash
docker compose exec mysql sh -c 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" "$MYSQL_DATABASE" -e "SELECT COUNT(*) AS members FROM members;"'
```

## 5. Nginx Reverse Proxy – Commit 1

Kiểm tra:

```bash
curl -I http://localhost:8080
```

Kỳ vọng `HTTP/1.1 200 OK` và các header `X-Frame-Options`, `X-Content-Type-Options`, `Referrer-Policy`, `Permissions-Policy`. File cấu hình: `nginx/default.conf`.

## 6. Prometheus + Grafana – Commit 2

Mở `http://localhost:9090/targets` xem các job hoạt động. Trong Prometheus thử:

```promql
probe_success{job="club-http"}
```

Giá trị `1` là Blackbox truy cập được website thông qua Nginx.

Grafana được cấu hình bằng `monitoring/provisioning` để tự thêm data source `prometheus`, `loki` và tải 3 dashboard JSON trong `monitoring/dashboards`:

- **Node Exporter Full**: CPU, RAM, ổ đĩa máy ảo.
- **cAdvisor - Club Containers**: CPU, RAM Docker containers.
- **MySQL Overview - Club Database**: MySQL Uptime, QPS, connections.

Dữ liệu có thể xuất hiện sau 1–2 phút. Dashboard mẫu có thể có panel `No data` nếu metric phiên bản hiện tại khác với dashboard nguồn; các panel chính cần được xác nhận khi demo.

## 7. Loki + Promtail + LogQL – Commit 3

Grafana → **Explore** → chọn data source `loki`, thời gian Last 15 minutes hoặc Last 1 hour:

Xem log container:

```logql
{job="docker"}
```

Lọc log chứa `error`:

```logql
{job="docker"} |= "error"
```

Thống kê số dòng log trong 5 phút:

```logql
sum(count_over_time({job="docker"}[5m]))
```

Promtail đọc các file `*-json.log` trong `/var/lib/docker/containers`. Để xem kết quả có dữ liệu, hãy tạo một vài request tới website (reload trình duyệt). Nếu query lọc `error` rỗng thì không đồng nghĩa hệ thống bị lỗi.

> Promtail được giữ theo yêu cầu học phần, không phải công cụ khuyến nghị cho dự án production mới.

## 8. Hardening – 4 biện pháp

1. **Nginx Security Headers**: xem file `nginx/default.conf`, kiểm chứng bằng `curl -I`.
2. **Network Isolation**: `app-network` (website/DB) và `monitoring` (metrics/log); exporter kết nối hai mạng khi cần thiết. Đây là tách mạng theo vai trò, chưa phải cô lập tuyệt đối.
3. **Non-root container**: `blackbox-exporter` dùng UID/GID `65534:65534`.
4. **Least Privilege**: `no-new-privileges:true`, `cap_drop: ALL` trên Blackbox Exporter.

Kiểm tra:

```bash
docker compose exec blackbox-exporter id
docker inspect club-management-blackbox-exporter-1 --format 'User={{.Config.User}} CapDrop={{json .HostConfig.CapDrop}} SecurityOpt={{json .HostConfig.SecurityOpt}}'
```

## 9. Các file chính

```text
backend/index.php                          Website PHP CLB
backend/Dockerfile                         Image PHP 8.3 + PDO MySQL
database/init.sql                          5 bảng + dữ liệu mẫu
database/02-create-exporter.sh             Tài khoản MySQL Exporter lúc init
nginx/default.conf                         Reverse Proxy + Headers
monitoring/prometheus.yml                  Jobs giám sát
monitoring/blackbox.yml                    HTTP probe
monitoring/loki.yml                        Loki
monitoring/promtail.yml                    Đọc Docker logs
monitoring/provisioning/                   Datasource + Dashboard provisioning
monitoring/dashboards/*.json               Dashboard mẫu
compose.yml                                Toàn bộ Docker services
.env.example                               Biến môi trường mẫu
```

## 10. GitHub và các commit

Dự án Đề 39 sử dụng **repository và tài khoản GitHub khác Đề 5**. Không sao chép thư mục `.git` cũ. Đặt email tác giả theo tài khoản mới:

```bash
git init
git branch -M main
git config user.name "TEN_TAI_KHOAN_MOI"
git config user.email "EMAIL_XAC_MINH_TREN_GITHUB"
```

Đề bài yêu cầu có commit theo các mốc: khởi tạo ứng dụng/DB, **Commit 1** Nginx, **Commit 2** Prometheus/Grafana, **Commit 3** Loki/Promtail, cuối cùng Hardening. Chỉ tạo những commit gắn với thay đổi thực tế; không tạo commit rỗng để giả lập tiến độ. Nếu đã sao chép bản hoàn chỉnh này, có thể lưu một commit source ban đầu, sau đó commit các thay đổi phát sinh trong lúc kiểm thử/hoàn thiện; nếu giảng viên yêu cầu đúng thứ tự mốc, nên tổ chức commit trước theo các giai đoạn thay vì add tất cả ngay lập tức.

## 11. Giới hạn / lưu ý

- Dự án phục vụ **demo môn học trên một máy ảo**, không có đăng nhập/phân quyền ứng dụng cho người dùng và chưa tối ưu cho internet công khai.
- File `.env` chỉ được tạo **trên máy ảo**, không nằm trong ZIP và không được đưa lên GitHub.
- Khi đổi mật khẩu trong `.env` của một MySQL volume đã có dữ liệu, tài khoản MySQL **không tự đổi mật khẩu**; hãy quản lý việc đổi mật khẩu bên trong database.
- Nếu cAdvisor không nhận container ở Docker 29, kiểm tra Docker image store (`docker info --format '{{.Driver}} {{json .DriverStatus}}'`); đã từng gặp trường hợp cần classic `overlay2` trên Ubuntu.
- Kiểm tra Promtail dùng log driver phù hợp: `docker info --format '{{.LoggingDriver}}'` phải cho ra `json-file` với cấu hình hiện tại.
