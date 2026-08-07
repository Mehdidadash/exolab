<?php
// panel/view_case.php
require_once __DIR__ . '/auth.php';
require_login();

// CSRF token for AJAX actions
if (empty($_SESSION['_csrf_token'])) {
    $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['_csrf_token'];
session_write_close();

$id = !empty($_GET['id']) ? (int) $_GET['id'] : 0;
$case = null;
$files = [];
$user = current_user();
$isDoctor = ($user['role'] === 'doctor');
$doctorId = $isDoctor ? $user['id'] : null;

if ($id) {
    $sql = 'SELECT c.*, u.full_name AS doctor_name, p.title AS service_title, d.full_name AS designer_name,
                   cs.name AS status_name, lab.full_name AS lab_name
            FROM cases c
            LEFT JOIN users u ON c.doctor_id = u.id
            LEFT JOIN site_prices p ON c.service_id = p.id
            LEFT JOIN users d ON c.designer_id = d.id
            LEFT JOIN case_statuses cs ON c.status_id = cs.id
            LEFT JOIN users lab ON c.lab_id = lab.id
            WHERE c.id = ?';
    $params = [$id];
    if ($isDoctor) {
        $sql .= ' AND c.doctor_id = ?';
        $params[] = $doctorId;
    } elseif (!has_permission('view_all_cases')) {
        die('دسترسی غیرمجاز');
    }
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $case = $stmt->fetch();

    if ($case) {
        $fstmt = db()->prepare('SELECT * FROM case_files WHERE case_id = ? ORDER BY id ASC');
        $fstmt->execute([$id]);
        $files = $fstmt->fetchAll();

        // Get sub-cases (children)
        $subStmt = db()->prepare(
            'SELECT c.*, p.title AS service_title, cs.name AS status_name
             FROM cases c
             LEFT JOIN site_prices p ON c.service_id = p.id
             LEFT JOIN case_statuses cs ON c.status_id = cs.id
             WHERE c.parent_id = ?
             ORDER BY c.id ASC'
        );
        $subStmt->execute([$id]);
        $subCases = $subStmt->fetchAll();

        // Get parent case if this is a sub-case
        $parentCase = null;
        if ($case['parent_id']) {
            $pStmt = db()->prepare(
                'SELECT c.*, p.title AS service_title
                 FROM cases c
                 LEFT JOIN site_prices p ON c.service_id = p.id
                 WHERE c.id = ?'
            );
            $pStmt->execute([$case['parent_id']]);
            $parentCase = $pStmt->fetch();
        }
    }
}

panel_layout_start('مشاهده کیس');
?>
<div class="form-card">
    <?php if (!$case): ?>
        <p>کیسی یافت نشد.</p>
    <?php else: ?>
        <h3>کیس #<?= htmlspecialchars($case['id']) ?> - <?= htmlspecialchars($case['patient_name']) ?><?= !empty($case['receipt_number']) ? ' / ' . htmlspecialchars($case['receipt_number']) : '' ?></h3>

        <?php $isRestricted = in_array($user['role'] ?? '', ['doctor', 'clinic']); ?>

        <table style="width:100%; border-collapse:collapse; margin:12px 0;">
            <tr>
                <td style="padding:6px 8px; border-bottom:1px solid #eee;"><strong>پزشک:</strong></td>
                <td style="padding:6px 8px; border-bottom:1px solid #eee;"><?= htmlspecialchars($case['doctor_name'] ?? '—') ?></td>
                <td style="padding:6px 8px; border-bottom:1px solid #eee;"><strong>شماره قبض:</strong></td>
                <td style="padding:6px 8px; border-bottom:1px solid #eee;"><?= htmlspecialchars($case['receipt_number'] ?? '—') ?></td>
            </tr>
            <tr>
                <td style="padding:6px 8px; border-bottom:1px solid #eee;"><strong>خدمت:</strong></td>
                <td style="padding:6px 8px; border-bottom:1px solid #eee;"><?= htmlspecialchars($case['service_title'] ?? '—') ?></td>
                <td style="padding:6px 8px; border-bottom:1px solid #eee;"><strong>وضعیت:</strong></td>
                <td style="padding:6px 8px; border-bottom:1px solid #eee;"><span class="badge"><?= htmlspecialchars($case['status_name'] ?? '—') ?></span></td>
            </tr>
            <tr>
                <td style="padding:6px 8px; border-bottom:1px solid #eee;"><strong>تاریخ دریافت:</strong></td>
                <td style="padding:6px 8px; border-bottom:1px solid #eee;"><?= htmlspecialchars(toJalaliDateFormatted($case['received_date'])) ?></td>
                <td style="padding:6px 8px; border-bottom:1px solid #eee;"><strong>مکان / دندان:</strong></td>
                <td style="padding:6px 8px; border-bottom:1px solid #eee;"><?= htmlspecialchars(formatCaseLocation($case['location_type'], $case['teeth'])) ?></td>
            </tr>
            <tr>
                <td style="padding:6px 8px; border-bottom:1px solid #eee;"><strong>سایه:</strong></td>
                <td style="padding:6px 8px; border-bottom:1px solid #eee;"><?= htmlspecialchars($case['shade'] ?? '—') ?></td>
                <td style="padding:6px 8px; border-bottom:1px solid #eee;"><strong>تعداد:</strong></td>
                <td style="padding:6px 8px; border-bottom:1px solid #eee;"><?= toPersianDigits((int)($case['quantity'] ?? 1)) ?></td>
            </tr>
            <?php if (!$isRestricted): ?>
            <tr>
                <td style="padding:6px 8px; border-bottom:1px solid #eee;"><strong>لابراتوار:</strong></td>
                <td style="padding:6px 8px; border-bottom:1px solid #eee;"><?= htmlspecialchars($case['lab_name'] ?? '—') ?></td>
                <td style="padding:6px 8px; border-bottom:1px solid #eee;"><strong>طراح:</strong></td>
                <td style="padding:6px 8px; border-bottom:1px solid #eee;"><?= htmlspecialchars($case['designer_name'] ?? '—') ?></td>
            </tr>
            <tr>
                <td style="padding:6px 8px; border-bottom:1px solid #eee;"><strong>فی (تومان):</strong></td>
                <td style="padding:6px 8px; border-bottom:1px solid #eee;"><?= $case['unit_price'] ? formatAmountToman($case['unit_price']) : '—' ?></td>
                <td style="padding:6px 8px; border-bottom:1px solid #eee;"><strong>جمع کل:</strong></td>
                <td style="padding:6px 8px; border-bottom:1px solid #eee;"><?= $case['total_price'] ? formatAmountToman($case['total_price']) : '—' ?></td>
            </tr>
            <?php endif; ?>
            <?php if (!empty($case['description'])): ?>
            <tr>
                <td style="padding:6px 8px; border-bottom:1px solid #eee;"><strong>توضیحات:</strong></td>
                <td colspan="3" style="padding:6px 8px; border-bottom:1px solid #eee;"><?= htmlspecialchars($case['description']) ?></td>
            </tr>
            <?php endif; ?>
        </table>

        <?php if ($parentCase): ?>
            <p><strong>کیس اصلی:</strong> <a href="view_case.php?id=<?= $parentCase['id'] ?>">#<?= $parentCase['id'] ?> - <?= htmlspecialchars($parentCase['patient_name']) ?> (<?= htmlspecialchars($parentCase['service_title']) ?>)</a></p>
        <?php endif; ?>

        <?php if (!empty($subCases)): ?>
            <h4>کیس‌های وابسته (زیرمجموعه)</h4>
            <ul>
                <?php foreach ($subCases as $sc): ?>
                    <li><a href="view_case.php?id=<?= $sc['id'] ?>">#<?= $sc['id'] ?> - <?= htmlspecialchars($sc['patient_name']) ?> (<?= htmlspecialchars($sc['service_title']) ?>)</a> – <span class="badge"><?= htmlspecialchars($sc['status_name']) ?></span></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
        <?php if (has_permission('create_cases') || has_role('admin')): ?>
            <p style="margin-top:12px;"><a class="btn" href="cases.php?add_sub=<?= $case['id'] ?>" style="background:#0F172A; color:#fff;">➕ افزودن کیس زیرمجموعه</a></p>
        <?php endif; ?>

        <h4>فایل‌ها</h4>
        <?php if (empty($files)): ?>
            <p>هیچ فایلی آپلود نشده است.</p>
        <?php else: ?>
            <div style="display:flex; gap:8px; flex-wrap:wrap; margin-bottom:12px;">
                <?php 
                $imageExts = ['jpg','jpeg','png','gif','webp','bmp'];
                foreach ($files as $f): 
                    $ext = strtolower(pathinfo($f['filename'], PATHINFO_EXTENSION));
                    $isImage = in_array($ext, $imageExts);
                    $fileUrl = '../assets/uploads/cases/'.$case['id'].'/'.$f['filename'];
                ?>
                    <div style="display:flex; gap:4px; align-items:center; background:#f9fafb; border:1px solid #e5e7eb; border-radius:8px; padding:4px 8px;">
                        <?php if ($isImage): ?>
                            <button class="btn file-image" data-file="<?= htmlspecialchars($fileUrl) ?>" style="background:#e0f2fe; color:#0369a1; padding:4px 10px; font-size:0.85rem;">
                                🖼 <?= htmlspecialchars($f['original_name']) ?>
                            </button>
                        <?php else: ?>
                            <button class="btn file-load" data-file="<?= htmlspecialchars($fileUrl) ?>" style="padding:4px 10px; font-size:0.85rem;">
                                <?= htmlspecialchars($f['original_name']) ?>
                            </button>
                        <?php endif; ?>
                        <?php if (!in_array($user['role'] ?? '', ['doctor', 'clinic'])): ?>
                            <a class="btn" href="download_case_file.php?id=<?= $f['id'] ?>" style="background:#E5E7EB; color:#0F172A; padding:4px 6px; font-size:0.8rem; text-decoration:none;" title="دانلود">⬇️</a>
                        <?php endif; ?>
                        <?php if (has_permission('edit_cases') || has_permission('upload_files')): ?>
                            <button class="btn file-action-rename" style="background:#F3F4F6; color:#111; padding:4px 6px; font-size:0.8rem;" data-id="<?= $f['id'] ?>" data-name="<?= htmlspecialchars($f['original_name']) ?>">✏️</button>
                            <button class="btn file-action-delete" style="background:#fee2e2; color:#a00; padding:4px 6px; font-size:0.8rem;" data-id="<?= $f['id'] ?>" data-name="<?= htmlspecialchars($f['original_name']) ?>">🗑</button>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>

            <!-- Image Viewer (hidden by default) -->
            <div id="image-viewer-container" style="display:none; width:100%; margin-bottom:16px;">
                <img id="image-viewer" src="" alt="preview" style="max-width:100%; max-height:600px; border-radius:8px; box-shadow:0 4px 12px rgba(0,0,0,0.1); display:block; margin:0 auto;">
                <div style="text-align:center; margin-top:8px;">
                    <button id="close-image-viewer" class="btn" style="background:#E5E7EB; color:#0F172A;">بستن تصویر</button>
                </div>
            </div>

            <!-- 3D Viewer -->
            <div id="viewer" style="width:100%; height:520px; background:linear-gradient(135deg, #f3f4f6 0%, #e5e7eb 100%); border:1px solid #d1d5db; border-radius:12px; position:relative; overflow:hidden;">
                <div id="viewer-overlay" style="position:absolute; right:8px; top:8px; background:rgba(255,255,255,0.95); border-radius:10px; padding:8px; box-shadow:0 4px 16px rgba(0,0,0,0.1); z-index:1000; font-size:13px; backdrop-filter:blur(4px);">
                    <div style="display:flex; gap:4px; margin-bottom:6px;">
                        <button id="rot-left" class="btn" style="padding:4px 8px; font-size:1rem;" title="چرخش به چپ">⟲</button>
                        <button id="rot-right" class="btn" style="padding:4px 8px; font-size:1rem;" title="چرخش به راست">⟳</button>
                        <button id="rot-up" class="btn" style="padding:4px 8px; font-size:1rem;" title="چرخش به بالا">↑</button>
                        <button id="rot-down" class="btn" style="padding:4px 8px; font-size:1rem;" title="چرخش به پایین">↓</button>
                    </div>
                    <div style="display:flex; gap:4px; margin-bottom:4px;">
                        <button id="zoom-in" class="btn" style="padding:4px 8px; font-size:1rem;">＋</button>
                        <button id="zoom-out" class="btn" style="padding:4px 8px; font-size:1rem;">−</button>
                        <button id="reset-view" class="btn" style="padding:4px 8px; font-size:0.8rem;">⟲ reset</button>
                    </div>
                    <div style="display:flex; gap:4px;">
                        <button id="view-top" class="btn" style="padding:4px 8px; font-size:0.8rem;">نمای بالا</button>
                        <button id="view-front" class="btn" style="padding:4px 8px; font-size:0.8rem;">نمای جلو</button>
                        <button id="view-side" class="btn" style="padding:4px 8px; font-size:0.8rem;">نمای کنار</button>
                    </div>
                    <div style="font-size:10px; color:#666; margin-top:4px; text-align:center;">کلیک+درگ = چرخش | اسکرول = زوم</div>
                </div>
                <div id="viewer-placeholder" style="position:absolute; inset:0; display:flex; align-items:center; justify-content:center; color:#9ca3af; font-size:1.1rem; pointer-events:none;">
                    روی فایل کلیک کنید تا نمایش داده شود
                </div>
            </div>
            <!-- Rename modal -->
            <div id="rename-modal" class="modal" style="display:none;">
                <div class="modal-content form-card" style="max-width:420px; margin:auto;">
                    <h3>ویرایش نام فایل</h3>
                    <form id="rename-form">
                        <input type="hidden" id="rename-file-id" name="id">
                        <input type="text" id="rename-file-name" name="name" style="width:100%; margin-top:8px;">
                        <div style="display:flex; gap:10px; justify-content:flex-end; margin-top:12px;">
                            <button type="button" id="rename-cancel" class="btn">انصراف</button>
                            <button type="submit" class="btn" style="background:#06B6D4;">ذخیره</button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Delete modal for files -->
            <div id="delete-modal-file" class="modal" style="display:none;">
                <div class="modal-content form-card" style="max-width:420px; margin:auto;">
                    <h3>حذف فایل</h3>
                    <p>آیا از حذف فایل <strong id="delete-file-name"></strong> مطمئن هستید؟</p>
                    <form id="delete-form">
                        <input type="hidden" id="delete-file-id" name="id">
                        <div style="display:flex; gap:10px; justify-content:flex-end; margin-top:12px;">
                            <button type="button" id="delete-cancel-file" class="btn">انصراف</button>
                            <button type="submit" class="btn" style="background:#f87171; color:#fff;">حذف</button>
                        </div>
                    </form>
                </div>
            </div>
            <script>window.CSRF_TOKEN = '<?= htmlspecialchars($csrf_token) ?>';</script>

            <!-- Image viewer script -->
            <script>
            (function() {
                var imgContainer = document.getElementById('image-viewer-container');
                var imgEl = document.getElementById('image-viewer');
                var closeBtn = document.getElementById('close-image-viewer');

                document.querySelectorAll('.file-image').forEach(function(btn) {
                    btn.addEventListener('click', function() {
                        var url = btn.getAttribute('data-file');
                        imgEl.src = url;
                        imgContainer.style.display = 'block';
                        // Scroll to image
                        imgContainer.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    });
                });

                if (closeBtn) {
                    closeBtn.addEventListener('click', function() {
                        imgContainer.style.display = 'none';
                        imgEl.src = '';
                    });
                }
            })();
            </script>

            <script type="module">
                import * as THREE from '../assets/js/three/three.module.js';
                import { STLLoader } from '../assets/js/three/STLLoader.module.js';
                import { PLYLoader } from '../assets/js/three/PLYLoader.module.js';
                import { OrbitControls } from '../assets/js/three/OrbitControls.module.js';

                const container = document.getElementById('viewer');
                const placeholder = document.getElementById('viewer-placeholder');
                const scene = new THREE.Scene();
                scene.background = new THREE.Color(0xf0f2f5);

                const camera = new THREE.PerspectiveCamera(40, container.clientWidth / container.clientHeight, 0.1, 1000);
                const renderer = new THREE.WebGLRenderer({ antialias: true });
                renderer.setPixelRatio(Math.min(window.devicePixelRatio, 2));
                renderer.setSize(container.clientWidth, container.clientHeight);
                renderer.shadowMap.enabled = true;
                container.appendChild(renderer.domElement);

                // Lighting
                const ambientLight = new THREE.AmbientLight(0x404060);
                scene.add(ambientLight);

                const keyLight = new THREE.DirectionalLight(0xffffff, 1.2);
                keyLight.position.set(2, 3, 4);
                scene.add(keyLight);

                const fillLight = new THREE.DirectionalLight(0x8888ff, 0.5);
                fillLight.position.set(-2, 1, -3);
                scene.add(fillLight);

                const rimLight = new THREE.DirectionalLight(0xffffff, 0.4);
                rimLight.position.set(0, -2, 2);
                scene.add(rimLight);

                // Ground grid
                const gridHelper = new THREE.GridHelper(200, 20, 0x444444, 0x888888);
                gridHelper.position.y = -20;
                scene.add(gridHelper);

                camera.position.set(100, 50, 100);
                camera.lookAt(0, 0, 0);

                let currentMesh = null;
                let currentScale = 1;
                const controls = new OrbitControls(camera, renderer.domElement);
                controls.enableDamping = true;
                controls.dampingFactor = 0.1;
                controls.minDistance = 5;
                controls.maxDistance = 500;
                controls.target.set(0, 0, 0);
                controls.update();

                function isGeometryValid(geometry) {
                    if (!geometry || !geometry.attributes || !geometry.attributes.position) return false;
                    const arr = geometry.attributes.position.array;
                    for (let i = 0; i < arr.length; i++) if (!isFinite(arr[i])) return false;
                    return true;
                }

                function centerMesh(mesh) {
                    mesh.geometry.computeBoundingBox();
                    const bbox = mesh.geometry.boundingBox;
                    const center = new THREE.Vector3();
                    bbox.getCenter(center);
                    mesh.position.sub(center);
                    const size = new THREE.Vector3();
                    bbox.getSize(size);
                    const max = Math.max(size.x, size.y, size.z) || 1;
                    currentScale = 80 / max;
                    mesh.scale.set(currentScale, currentScale, currentScale);
                    // Auto-position camera
                    const dist = max * 2.5;
                    camera.position.set(dist * 0.7, dist * 0.4, dist);
                    controls.target.set(0, 0, 0);
                    controls.update();
                }

                function loadFile(url) {
                    if (placeholder) placeholder.style.display = 'none';
                    if (currentMesh) {
                        scene.remove(currentMesh);
                        currentMesh.geometry.dispose();
                        currentMesh.material.dispose();
                        currentMesh = null;
                    }

                    const ext = url.split('.').pop().toLowerCase();

                    function onGeometry(geometry, useVertexColors) {
                        if (!isGeometryValid(geometry)) {
                            alert('خطا: هندسه فایل نامعتبر است.');
                            return;
                        }
                        geometry.computeVertexNormals();
                        const material = new THREE.MeshStandardMaterial({
                            color: useVertexColors ? 0xffffff : 0x88aacc,
                            vertexColors: useVertexColors,
                            roughness: 0.4,
                            metalness: 0.1,
                            side: THREE.DoubleSide
                        });
                        const mesh = new THREE.Mesh(geometry, material);
                        mesh.castShadow = true;
                        mesh.receiveShadow = true;
                        centerMesh(mesh);
                        currentMesh = mesh;
                        scene.add(mesh);
                    }

                    if (ext === 'stl') {
                        const loader = new STLLoader();
                        loader.load(url, function(geometry) {
                            const hasColors = geometry.attributes.color !== undefined;
                            onGeometry(geometry, hasColors);
                        });
                    } else if (ext === 'ply') {
                        const loader = new PLYLoader();
                        loader.load(url, function(geometry) {
                            const hasColors = geometry.attributes.color !== undefined;
                            onGeometry(geometry, hasColors);
                        });
                    }
                }

                // Animation loop
                function animate() {
                    requestAnimationFrame(animate);
                    controls.update();
                    renderer.render(scene, camera);
                }
                animate();

                // Handle resize
                window.addEventListener('resize', function() {
                    const w = container.clientWidth;
                    const h = container.clientHeight;
                    camera.aspect = w / h;
                    camera.updateProjectionMatrix();
                    renderer.setSize(w, h);
                });

                // Wire 3D file buttons
                document.querySelectorAll('.file-load').forEach(function(btn) {
                    btn.addEventListener('click', function() {
                        loadFile(btn.getAttribute('data-file'));
                    });
                });

                // Overlay controls
                function rotateModel(axis, angle) {
                    if (!currentMesh) return;
                    const rad = THREE.MathUtils.degToRad(angle);
                    const q = new THREE.Quaternion().setFromAxisAngle(axis, rad);
                    currentMesh.quaternion.multiply(q);
                }

                document.getElementById('rot-left')?.addEventListener('click', function() {
                    rotateModel(new THREE.Vector3(0, 1, 0), -15);
                });
                document.getElementById('rot-right')?.addEventListener('click', function() {
                    rotateModel(new THREE.Vector3(0, 1, 0), 15);
                });
                document.getElementById('rot-up')?.addEventListener('click', function() {
                    rotateModel(new THREE.Vector3(1, 0, 0), -15);
                });
                document.getElementById('rot-down')?.addEventListener('click', function() {
                    rotateModel(new THREE.Vector3(1, 0, 0), 15);
                });
                document.getElementById('zoom-in')?.addEventListener('click', function() {
                    camera.position.multiplyScalar(0.85);
                    controls.update();
                });
                document.getElementById('zoom-out')?.addEventListener('click', function() {
                    camera.position.multiplyScalar(1.15);
                    controls.update();
                });
                document.getElementById('reset-view')?.addEventListener('click', function() {
                    if (!currentMesh) return;
                    currentMesh.quaternion.identity();
                    const size = currentMesh.geometry.boundingBox ? 
                        currentMesh.geometry.boundingBox.getSize(new THREE.Vector3()) : new THREE.Vector3(1,1,1);
                    const max = Math.max(size.x, size.y, size.z) || 1;
                    const dist = max * 2.5 / currentScale;
                    camera.position.set(dist * 0.7, dist * 0.4, dist);
                    controls.target.set(0, 0, 0);
                    controls.update();
                });
                document.getElementById('view-top')?.addEventListener('click', function() {
                    const dist = camera.position.length();
                    camera.position.set(0, dist, 0.01);
                    controls.target.set(0, 0, 0);
                    controls.update();
                });
                document.getElementById('view-front')?.addEventListener('click', function() {
                    const dist = camera.position.length();
                    camera.position.set(0, 0, dist);
                    controls.target.set(0, 0, 0);
                    controls.update();
                });
                document.getElementById('view-side')?.addEventListener('click', function() {
                    const dist = camera.position.length();
                    camera.position.set(dist, 0, 0.01);
                    controls.target.set(0, 0, 0);
                    controls.update();
                });

                // viewer overlay controls
                function rotateAroundY(angle) {
                    const pivot = controls.target.clone();
                    const pos = camera.position.clone().sub(pivot);
                    pos.applyAxisAngle(new THREE.Vector3(0,1,0), angle);
                    camera.position.copy(pivot.clone().add(pos));
                    camera.lookAt(pivot);
                    controls.update();
                }
                function rotateAroundX(angle) {
                    const pivot = controls.target.clone();
                    const pos = camera.position.clone().sub(pivot);
                    pos.applyAxisAngle(new THREE.Vector3(1,0,0), angle);
                    camera.position.copy(pivot.clone().add(pos));
                    camera.lookAt(pivot);
                    controls.update();
                }
                function zoomBy(factor) {
                    const dir = camera.position.clone().sub(controls.target).multiplyScalar(factor);
                    camera.position.copy(controls.target.clone().add(dir));
                    controls.update();
                }
                function resetView() {
                    camera.position.set(0,0,100);
                    controls.target.set(0,0,0);
                    controls.update();
                }
                document.getElementById('rot-left').addEventListener('click', ()=> rotateAroundY(0.2));
                document.getElementById('rot-right').addEventListener('click', ()=> rotateAroundY(-0.2));
                document.getElementById('rot-up').addEventListener('click', ()=> rotateAroundX(0.15));
                document.getElementById('rot-down').addEventListener('click', ()=> rotateAroundX(-0.15));
                document.getElementById('zoom-in').addEventListener('click', ()=> zoomBy(0.8));
                document.getElementById('zoom-out').addEventListener('click', ()=> zoomBy(1.25));
                document.getElementById('reset-view').addEventListener('click', resetView);

                document.querySelectorAll('.file-action-rename').forEach(function(btn){
                    btn.addEventListener('click', function(){
                        const id = btn.dataset.id; const name = btn.dataset.name;
                        document.getElementById('rename-file-id').value = id;
                        document.getElementById('rename-file-name').value = name;
                        document.getElementById('rename-modal').style.display = 'flex';
                    });
                });

                document.querySelectorAll('.file-action-delete').forEach(function(btn){
                    btn.addEventListener('click', function(){
                        const id = btn.dataset.id; const name = btn.dataset.name;
                        document.getElementById('delete-file-id').value = id;
                        document.getElementById('delete-file-name').textContent = name;
                        document.getElementById('delete-modal-file').style.display = 'flex';
                    });
                });

                document.getElementById('rename-cancel').addEventListener('click', function(){ document.getElementById('rename-modal').style.display='none'; });
                document.getElementById('delete-cancel-file').addEventListener('click', function(){ document.getElementById('delete-modal-file').style.display='none'; });

                document.getElementById('rename-form').addEventListener('submit', function(e){
                    e.preventDefault();
                    var id = document.getElementById('rename-file-id').value;
                    var name = document.getElementById('rename-file-name').value.trim();
                    if (!name) return alert('نام نباید خالی باشد');
                    fetch('rename_case_file.php', { method: 'POST', headers: {'Accept':'application/json', 'X-CSRF-Token': window.CSRF_TOKEN }, body: new URLSearchParams({ id: id, name: name, _csrf_token: window.CSRF_TOKEN }) }).then(r=>r.json()).then(function(data){
                        if (data && data.success) {
                            document.querySelectorAll('.file-action-rename, .file-action-delete, .file-load').forEach(function(el){
                                if (el.dataset.id == id) el.dataset.name = name;
                            });
                            document.querySelectorAll('.file-load').forEach(function(b){ if (b.dataset.file && b.nextElementSibling && b.nextElementSibling.dataset && b.nextElementSibling.dataset.id == id) { b.textContent = name; } });
                            document.getElementById('rename-modal').style.display='none';
                        } else {
                            alert('خطا در تغییر نام فایل');
                        }
                    }).catch(function(){ alert('خطا در درخواست'); });
                });

                document.getElementById('delete-form').addEventListener('submit', function(e){
                    e.preventDefault();
                    var id = document.getElementById('delete-file-id').value;
                    fetch('delete_case_file.php', { method: 'POST', headers: {'Accept':'application/json', 'X-CSRF-Token': window.CSRF_TOKEN }, body: new URLSearchParams({ id: id, _csrf_token: window.CSRF_TOKEN }) }).then(r=>r.json()).then(function(data){
                        if (data && data.success) {
                            document.querySelectorAll('.file-action-rename, .file-action-delete, .file-load').forEach(function(el){ if (el.dataset.id == id) { var wrapper = el.closest('div'); if (wrapper) wrapper.remove(); } });
                            document.getElementById('delete-modal-file').style.display='none';
                        } else {
                            alert('خطا در حذف فایل');
                        }
                    }).catch(function(){ alert('خطا در درخواست'); });
                });
            </script>
        <?php endif; ?>
    <?php endif; ?>
</div>

<?php panel_layout_end(); ?>