<?php
/**
 * AutoCare Car Maintenance Center - Public Homepage
 */
require_once __DIR__ . '/config.php';

$services = [];
try {
    $stmt = db()->query("SELECT * FROM services WHERE status = 'active' ORDER BY id DESC LIMIT 6");
    $services = $stmt->fetchAll();
} catch (PDOException $e) {
    // Graceful fallback if database not yet migrated
    $services = [];
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl" data-theme="<?= htmlspecialchars(current_theme()) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AutoCare - المركز المتخصص في صيانة وفحص السيارات</title>
    <script>
    (function() {
        var saved = localStorage.getItem('autocare_theme');
        if (!saved) {
            var match = document.cookie.match(new RegExp('(^| )autocare_theme=([^;]+)'));
            if (match) saved = match[2];
        }
        if (saved === 'light' || saved === 'dark') {
            document.documentElement.setAttribute('data-theme', saved);
        }
    })();
    </script>
    <link rel="stylesheet" href="style.css">
</head>
<body class="public-body">
    <!-- Navbar -->
    <header class="public-navbar">
        <div class="nav-container">
            <a href="index.php" class="brand-logo">
                <span class="brand-icon">⚡</span>
                <span class="brand-text">AutoCare</span>
            </a>
            <nav class="nav-links">
                <a href="#about">عن المركز</a>
                <a href="#services">الخدمات</a>
                <a href="#features">لماذا نحن</a>
                <a href="#workflow">خطوات العمل</a>
                <a href="#contact">تواصل معنا</a>
            </nav>
            <div class="nav-actions">
                <button type="button" class="theme-toggle-btn" onclick="toggleTheme()" title="تبديل المظهر بين الليلي والنهاري">
                    <span class="theme-icon"><?= current_theme() === 'dark' ? '☀️' : '🌙' ?></span>
                    
                </button>
                <?php if (is_logged_in()): ?>
                    <a href="dashboard.php" class="btn btn-primary btn-sm">لوحة التحكم (<?= htmlspecialchars($_SESSION['user_name'] ?? '') ?>)</a>
                    <a href="logout.php" class="btn btn-outline-danger btn-sm">خروج</a>
                <?php else: ?>
                    <a href="login.php" class="btn btn-outline btn-sm">تسجيل الدخول</a>
                    <a href="register.php" class="btn btn-primary btn-sm">تسجيل عميل</a>
                <?php endif; ?>
            </div>
        </div>
    </header>

    <!-- Hero Section -->
    <section class="hero-section">
        <div class="hero-overlay"></div>
        <div class="hero-content">
            <div class="hero-badge">🔧 مركز الصيانة الرائد والمتكامل</div>
            <h1 class="hero-title">عناية فائقة، فحص دقيق، وخدمات صيانة معتمدة لسيارتك</h1>
            <p class="hero-subtitle">
                نقدم أعلى معايير الجودة في صيانة المحركات، فحص الكمبيوتر، الفرامل، الكهرباء وتغيير الزيوت مع متابعة إلكترونية مباشرة لكل مرحلة من مراحل الصيانة وفواتير إلكترونية شفافة.
            </p>
            <div class="hero-cta">
                <?php if (!is_logged_in()): ?>
                    <a href="register.php" class="btn btn-primary btn-lg">ابدأ الآن وسجّل سيارتك</a>
                    <a href="login.php" class="btn btn-secondary btn-lg">دخول النظام</a>
                <?php else: ?>
                    <a href="dashboard.php" class="btn btn-primary btn-lg">الانتقال للوحة التحكم</a>
                <?php endif; ?>
            </div>
            <div class="hero-stats-banner">
                <div class="stat-pill">
                    <span class="stat-icon">⏱️</span>
                    <span>ساعات العمل: السبت - الخميس 8:00 ص - 10:00 م</span>
                </div>
                <div class="stat-pill">
                    <span class="stat-icon">📍</span>
                    <span>الموقع: المنطقة الصناعية الرئيسية - فرع المحركات</span>
                </div>
                <div class="stat-pill">
                    <span class="stat-icon">🛡️</span>
                    <span>ضمان الجودة وقطع غيار أصلية معتمدة</span>
                </div>
            </div>
        </div>
    </section>

    <!-- About Section -->
    <section id="about" class="section-padded">
        <div class="container">
            <div class="section-header">
                <span class="section-tag">نبذة عن المركز</span>
                <h2>خبرة ممتدة واحترافية متكاملة</h2>
                <p>نعمل بأحدث أجهزة التشخيص والبرمجة وفريق فنيين متخصصين لضمان أعلى أداء لمركبتك على الطريق.</p>
            </div>
            <div class="grid grid-3">
                <div class="card feature-card">
                    <div class="card-icon">💻</div>
                    <h3>تشخيص بالكمبيوتر</h3>
                    <p>فحص شامل للحساسات، وحدات التحكم، والمحرك بأحدث البرمجيات المعتمدة لكشف الأعطال بدقة متناهية.</p>
                </div>
                <div class="card feature-card">
                    <div class="card-icon">👨‍🔧</div>
                    <h3>طاقم فني معتمد</h3>
                    <p>فريق من المهندسين والفنيين ذوي الخبرة العالية في صيانة كافة الماركات والموديلات العالمية.</p>
                </div>
                <div class="card feature-card">
                    <div class="card-icon">🧾</div>
                    <h3>شفافية كاملة</h3>
                    <p>أوامر صيانة مفصلة وفواتير رقمية معتمدة وتحديث دوري لحالة السيارة عبر لوحة تحكم العميل.</p>
                </div>
            </div>
        </div>
    </section>

    <!-- Services Section -->
    <section id="services" class="section-padded bg-darker">
        <div class="container">
            <div class="section-header">
                <span class="section-tag">خدماتنا المعتمدة</span>
                <h2>قائمة الخدمات وأسعار الصيانة</h2>
                <p>نقدم باقة واسعة من خدمات الصيانة الوقائية والتصحيحية لكافة أنظمة السيارة.</p>
            </div>

            <?php if (!empty($services)): ?>
                <div class="grid grid-3">
                    <?php foreach ($services as $srv): ?>
                        <div class="card service-card">
                            <div class="service-header">
                                <span class="service-icon">🛠️</span>
                                <span class="service-price"><?= format_money($srv['price']) ?></span>
                            </div>
                            <h3><?= htmlspecialchars($srv['name']) ?></h3>
                            <p><?= htmlspecialchars($srv['description'] ?: 'خدمة صيانة شاملة ومضمونة بأيدي فنيين محترفين.') ?></p>
                            <div class="service-footer">
                                <span class="badge badge-success">متاحة ومجدولة</span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="empty-state card">
                    <div class="empty-icon">🚗</div>
                    <h3>لا توجد خدمات مضافة حالياً في قاعدة البيانات</h3>
                    <p>سيتم إدراج باقات وخدمات الصيانة فور إضافتها من قبل مدير النظام.</p>
                </div>
            <?php endif; ?>
        </div>
    </section>

    <!-- Features Section -->
    <section id="features" class="section-padded">
        <div class="container">
            <div class="section-header">
                <span class="section-tag">المميزات التنافسية</span>
                <h2>لماذا تختار مركز AutoCare؟</h2>
            </div>
            <div class="grid grid-4">
                <div class="card mini-card">
                    <div class="mini-icon">🔍</div>
                    <h4>فحص 360 درجة</h4>
                    <p>فحص دقيق لكافة السوائل، الإطارات، البطارية، وأنظمة التعليق.</p>
                </div>
                <div class="card mini-card">
                    <div class="mini-icon">📱</div>
                    <h4>تتبع إلكتروني</h4>
                    <p>تابع حالة صيانة سيارتك من جوالك خطوة بخطوة حتى الاستلام.</p>
                </div>
                <div class="card mini-card">
                    <div class="mini-icon">💳</div>
                    <h4>طرق دفع متعددة</h4>
                    <p>خيارات دفع آمنة تشمل النقد، مدى، البطاقات الائتمانية والتحويل.</p>
                </div>
                <div class="card mini-card">
                    <div class="mini-icon">📑</div>
                    <h4>سجل صيانة رقمي</h4>
                    <p>أرشيف كامل لجميع صيانات سيارتك السابقة للرجوع إليها في أي وقت.</p>
                </div>
            </div>
        </div>
    </section>

    <!-- Workflow Section -->
    <section id="workflow" class="section-padded bg-darker">
        <div class="container">
            <div class="section-header">
                <span class="section-tag">آلية العمل</span>
                <h2>4 خطوات سهلة لصيانة سيارتك</h2>
            </div>
            <div class="steps-container">
                <div class="step-card">
                    <div class="step-number">01</div>
                    <h4>تسجيل السيارة</h4>
                    <p>تسجيل العميل وإدخال بيانات السيارة ورقم اللوحة وإصدار أمر الصيانة.</p>
                </div>
                <div class="step-card">
                    <div class="step-number">02</div>
                    <h4>الفحص والتشخيص</h4>
                    <p>فحص السيارة بالكمبيوتر وتحديد العطل وتقدير التكلفة الأولية.</p>
                </div>
                <div class="step-card">
                    <div class="step-number">03</div>
                    <h4>التنفيذ والإصلاح</h4>
                    <p>صيانة دقيقة باستخدام قطع غيار مطابقة للمواصفات واختبار الجودة.</p>
                </div>
                <div class="step-card">
                    <div class="step-number">04</div>
                    <h4>الفاتورة والتسليم</h4>
                    <p>إصدار الفاتورة الإلكترونية، السداد، وتسليم السيارة للعميل بضمان.</p>
                </div>
            </div>
        </div>
    </section>

    <!-- Contact & Footer -->
    <footer id="contact" class="public-footer">
        <div class="container footer-grid">
            <div class="footer-col">
                <div class="brand-logo mb-3">
                    <span class="brand-icon">⚡</span>
                    <span class="brand-text">AutoCare</span>
                </div>
                <p>نظام متكامل لإدارة مراكز صيانة السيارات وخدمات المركبات، مبني بأحدث التقنيات وأعلى معايير الأمان والسرعة.</p>
            </div>
            <div class="footer-col">
                <h4>روابط سريعة</h4>
                <ul>
                    <li><a href="login.php">تسجيل الدخول للنظام</a></li>
                    <li><a href="register.php">تسجيل حساب عميل</a></li>
                    <li><a href="#services">الخدمات والأسعار</a></li>
                    <li><a href="#about">عن المركز</a></li>
                </ul>
            </div>
            <div class="footer-col">
                <h4>معلومات التواصل</h4>
                <p>📍 العنوان: شارع الصناعية العام، مبنى AutoCare</p>
                <p>📞 الهاتف: 920001234</p>
                <p>✉️ البريد: support@autocare-system.com</p>
                <p>⏰ أوقات العمل: 8:00 صباحاً - 10:00 مساءً</p>
            </div>
        </div>
        <div class="footer-bottom">
            <p>© <?= date('Y') ?> جميع الحقوق محفوظة لمركز AutoCare لإدارة صيانة السيارات.</p>
        </div>
    </footer>
    <script src="app.js"></script>
</body>
</html>
