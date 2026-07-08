<?php
// panel/view_case.php
require_once __DIR__ . '/auth.php';
require_login();

// CSRF token for AJAX actions
if (empty($_SESSION['_csrf_token'])) {
    $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['_csrf_token'];

$id = !empty($_GET['id']) ? (int) $_GET['id'] : 0;
$case = null;
$files = [];
$user = current_user();
$isDoctor = ($user['role'] === 'doctor');
$doctorId = $isDoctor ? $user['id'] : null;

if ($id) {
    $sql = 'SELECT c.*, u.full_name AS doctor_name, p.title AS service_title
            FROM cases c
            LEFT JOIN users u ON c.doctor_id = u.id
            LEFT JOIN site_prices p ON c.service_id = p.id
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
    }
}

panel_layout_start('مشاهده کیس');
?>
<div class="form-card">
    <?php if (!$case): ?>
        <p>کیسی یافت نشد.</p>
    <?php else: ?>
        <h3>کیس #<?= htmlspecialchars($case['id']) ?> - <?= htmlspecialchars($case['patient_name']) ?></h3>
        <p><strong>پزشک:</strong> <?= htmlspecialchars($case['doctor_name']) ?></p>
        <p><strong>خدمت:</strong> <?= htmlspecialchars($case['service_title']) ?></p>
        <p><strong>تاریخ دریافت:</strong> <?= htmlspecialchars(toJalaliDateFormatted($case['received_date'])) ?></p>

        <h4>فایل‌ها</h4>
        <?php if (empty($files)): ?>
            <p>هیچ فایلی آپلود نشده است.</p>
        <?php else: ?>
            <div style="display:flex; gap:10px; flex-wrap:wrap; margin-bottom:12px;">
                <?php foreach ($files as $f): ?>
                    <div style="display:flex; gap:6px; align-items:center;">
                        <button class="btn file-load" data-file="<?= htmlspecialchars('../assets/uploads/cases/'.$case['id'].'/'.$f['filename']) ?>"><?= htmlspecialchars($f['original_name']) ?></button>
                        <?php if (has_permission('edit_cases') || has_permission('upload_files')): ?>
                            <button class="btn file-action-rename" style="background:#F3F4F6; color:#111;" data-id="<?= $f['id'] ?>" data-name="<?= htmlspecialchars($f['original_name']) ?>">ویرایش</button>
                            <button class="btn file-action-delete" style="background:#fee2e2; color:#a00;" data-id="<?= $f['id'] ?>" data-name="<?= htmlspecialchars($f['original_name']) ?>">حذف</button>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>

            <div id="viewer" style="width:100%; height:480px; background:#f3f4f6; border:1px solid #e5e7eb; position:relative;">
                <div id="viewer-overlay" style="position:absolute; right:8px; top:8px; background:rgba(255,255,255,0.92); border-radius:6px; padding:6px; box-shadow:0 4px 10px rgba(0,0,0,0.08); z-index:1000; font-size:13px;">
                    <div style="display:flex; gap:6px; margin-bottom:6px;">
                        <button id="rot-left" class="btn" style="padding:6px;">⟲</button>
                        <button id="rot-right" class="btn" style="padding:6px;">⟳</button>
                        <button id="rot-up" class="btn" style="padding:6px;">↑</button>
                        <button id="rot-down" class="btn" style="padding:6px;">↓</button>
                    </div>
                    <div style="display:flex; gap:6px; margin-bottom:6px;">
                        <button id="zoom-in" class="btn" style="padding:6px;">＋</button>
                        <button id="zoom-out" class="btn" style="padding:6px;">−</button>
                        <button id="reset-view" class="btn" style="padding:6px;">⟲ reset</button>
                    </div>
                    <div style="font-size:11px; color:#444;">با کشیدن ماوس بچرخانید، اسکرول برای زوم</div>
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
            <script type="module">
                // (the same Three.js code as before – unchanged)
                import * as THREE from '../assets/js/three/three.module.js';
                import { STLLoader } from '../assets/js/three/STLLoader.module.js';
                import { PLYLoader } from '../assets/js/three/PLYLoader.module.js';
                import { OrbitControls } from '../assets/js/three/OrbitControls.module.js';

                const container = document.getElementById('viewer');
                const scene = new THREE.Scene();
                const camera = new THREE.PerspectiveCamera(45, container.clientWidth / container.clientHeight, 0.1, 1000);
                const renderer = new THREE.WebGLRenderer({ antialias: true });
                renderer.setSize(container.clientWidth, container.clientHeight);
                container.appendChild(renderer.domElement);

                const light = new THREE.DirectionalLight(0xffffff, 1);
                light.position.set(1,1,1).normalize();
                scene.add(light);
                scene.add(new THREE.AmbientLight(0x888888));

                camera.position.z = 100;
                let currentMesh = null;
                const controls = new OrbitControls( camera, renderer.domElement );
                controls.enableDamping = true;
                controls.dampingFactor = 0.08;
                controls.screenSpacePanning = false;
                controls.minDistance = 10;
                controls.maxDistance = 1000;

                function isGeometryValid(geometry) {
                    if (!geometry || !geometry.attributes || !geometry.attributes.position) return false;
                    const arr = geometry.attributes.position.array;
                    for (let i=0;i<arr.length;i++) if (!isFinite(arr[i])) return false;
                    return true;
                }

                async function loadFile(url) {
                    if (currentMesh) { scene.remove(currentMesh); currentMesh.geometry.dispose(); currentMesh.material.dispose(); currentMesh = null; }
                    const ext = url.split('.').pop().toLowerCase();
                    if (ext === 'stl') {
                        const loader = new STLLoader();
                        loader.load(url, (geometry)=>{
                            if (!isGeometryValid(geometry)) { console.error('Invalid STL geometry', geometry); alert('خطا: هندسه فایل STL نامعتبر است.'); return; }
                            const useVertexColors = geometry.attributes.color !== undefined;
                            const material = new THREE.MeshPhongMaterial({ color: 0xaaaaaa, vertexColors: useVertexColors });
                            const mesh = new THREE.Mesh(geometry, material);
                            geometry.computeBoundingBox();
                            const bbox = geometry.boundingBox;
                            const size = new THREE.Vector3(); bbox.getSize(size);
                            const max = Math.max(size.x, size.y, size.z) || 1;
                            mesh.scale.multiplyScalar(60 / max);
                            mesh.position.set(0, -10, 0);
                            currentMesh = mesh;
                            scene.add(mesh);
                        });
                    } else if (ext === 'ply') {
                        const loader = new PLYLoader();
                        loader.load(url, (geometry)=>{
                            if (!isGeometryValid(geometry)) { console.error('Invalid PLY geometry', geometry); alert('خطا: هندسه فایل PLY نامعتبر است.'); return; }
                            geometry.computeVertexNormals();
                            const useVertexColors = geometry.attributes.color !== undefined;
                            const material = new THREE.MeshStandardMaterial({ color: 0xcccccc, vertexColors: useVertexColors });
                            const mesh = new THREE.Mesh(geometry, material);
                            geometry.computeBoundingBox();
                            const bbox = geometry.boundingBox;
                            const size = new THREE.Vector3(); bbox.getSize(size);
                            const max = Math.max(size.x, size.y, size.z) || 1;
                            mesh.scale.multiplyScalar(60 / max);
                            mesh.position.set(0, -10, 0);
                            currentMesh = mesh;
                            scene.add(mesh);
                        });
                    }
                }

                function animate(){ requestAnimationFrame(animate); controls.update(); renderer.render(scene, camera); }
                animate();

                // wire file actions (load, rename, delete)
                document.querySelectorAll('.file-load').forEach(function(btn){
                    btn.addEventListener('click', function(){
                        const url = btn.getAttribute('data-file');
                        loadFile(url);
                    });
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
                    fetch('rename_case_file.php', { method: 'POST', headers: {'Accept':'application/json'}, body: new URLSearchParams({ id: id, name: name, _csrf_token: window.CSRF_TOKEN }) }).then(r=>r.json()).then(function(data){
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
                    fetch('delete_case_file.php', { method: 'POST', headers: {'Accept':'application/json'}, body: new URLSearchParams({ id: id, _csrf_token: window.CSRF_TOKEN }) }).then(r=>r.json()).then(function(data){
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