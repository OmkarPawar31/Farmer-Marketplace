<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in and is a farmer
if (!isset($_SESSION['logged_in']) || $_SESSION['user_type'] !== 'farmer') {
    header('Location: login.php');
    exit();
}

$conn = getDB();
$user_id = $_SESSION['user_id'];

// Get all photos from farmer's listings
$stmt = $conn->prepare("
    SELECT p.id, p.images, p.title, p.description, c.name as crop_name, p.created_at, p.status
    FROM product_listings p 
    JOIN farmers f ON p.farmer_id = f.id 
    JOIN crops c ON p.crop_id = c.id
    WHERE f.user_id = ? AND p.images IS NOT NULL
    ORDER BY p.created_at DESC
");
$stmt->execute([$user_id]);
$gallery_items = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Crop Photo Gallery - Farmer Marketplace</title>
    <link rel="stylesheet" href="../css/style.css">
    <link rel="stylesheet" href="../css/responsive.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        .gallery-container {
            max-width: 1200px;
            margin: 80px auto;
            padding: 20px;
        }
        
        .gallery-header {
            background: linear-gradient(135deg, #264653 0%, #2a9d8f 100%);
            color: white;
            padding: 30px;
            border-radius: 20px;
            margin-bottom: 30px;
            text-align: center;
        }
        
        .gallery-header h1 {
            font-size: 2.5rem;
            margin-bottom: 10px;
        }
        
        .gallery-header p {
            opacity: 0.9;
            font-size: 1.1rem;
        }
        
        .gallery-controls {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            background: white;
            padding: 20px;
            border-radius: 15px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
        }
        
        .gallery-stats {
            display: flex;
            gap: 30px;
        }
        
        .stat-item {
            text-align: center;
        }
        
        .stat-number {
            font-size: 1.5rem;
            font-weight: 700;
            color: #2a9d8f;
        }
        
        .stat-label {
            color: #666;
            font-size: 14px;
        }
        
        .gallery-filters {
            display: flex;
            gap: 10px;
            align-items: center;
        }
        
        .filter-btn {
            padding: 8px 20px;
            border: 2px solid #e0e0e0;
            background: white;
            border-radius: 25px;
            cursor: pointer;
            transition: all 0.3s ease;
            font-size: 14px;
        }
        
        .filter-btn.active {
            background: #2a9d8f;
            color: white;
            border-color: #2a9d8f;
        }
        
        .filter-btn:hover {
            border-color: #2a9d8f;
        }
        
        .gallery-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 25px;
            margin-bottom: 30px;
        }
        
        .gallery-card {
            background: white;
            border-radius: 20px;
            overflow: hidden;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
            transition: all 0.3s ease;
            position: relative;
        }
        
        .gallery-card:hover {
            transform: translateY(-10px);
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.15);
        }
        
        .gallery-image {
            position: relative;
            height: 250px;
            overflow: hidden;
        }
        
        .gallery-image img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            transition: transform 0.3s ease;
        }
        
        .gallery-card:hover .gallery-image img {
            transform: scale(1.1);
        }
        
        .image-overlay {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: linear-gradient(transparent, rgba(0, 0, 0, 0.7));
            opacity: 0;
            transition: opacity 0.3s ease;
            display: flex;
            align-items: flex-end;
            padding: 20px;
        }
        
        .gallery-card:hover .image-overlay {
            opacity: 1;
        }
        
        .image-info {
            color: white;
        }
        
        .image-actions {
            position: absolute;
            top: 15px;
            right: 15px;
            display: flex;
            gap: 10px;
        }
        
        .action-icon {
            width: 40px;
            height: 40px;
            background: rgba(255, 255, 255, 0.9);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.3s ease;
            color: #2a9d8f;
        }
        
        .action-icon:hover {
            background: white;
            transform: scale(1.1);
        }
        
        .card-content {
            padding: 20px;
        }
        
        .card-title {
            font-size: 1.2rem;
            font-weight: 600;
            color: #264653;
            margin-bottom: 8px;
        }
        
        .card-crop {
            color: #2a9d8f;
            font-weight: 500;
            margin-bottom: 10px;
        }
        
        .card-description {
            color: #666;
            font-size: 14px;
            line-height: 1.5;
            margin-bottom: 15px;
        }
        
        .card-meta {
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 12px;
            color: #999;
        }
        
        .status-badge {
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 500;
        }
        
        .status-active { background: #f0fdf4; color: #2a9d8f; }
        .status-sold { background: #fef2f2; color: #ef4444; }
        .status-expired { background: #f9fafb; color: #6b7280; }
        
        .no-photos {
            text-align: center;
            padding: 60px 20px;
            background: white;
            border-radius: 20px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
        }
        
        .no-photos i {
            font-size: 4rem;
            color: #ddd;
            margin-bottom: 20px;
        }
        
        .no-photos h3 {
            color: #264653;
            margin-bottom: 10px;
        }
        
        .no-photos p {
            color: #666;
            margin-bottom: 20px;
        }
        
        .btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 12px 24px;
            background: linear-gradient(135deg, #2a9d8f, #219f8b);
            color: white;
            text-decoration: none;
            border-radius: 10px;
            font-weight: 500;
            transition: all 0.3s ease;
        }
        
        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(42, 157, 143, 0.4);
        }
        
        /* Modal Styles */
        .modal {
            display: none;
            position: fixed;
            z-index: 10000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.9);
            align-items: center;
            justify-content: center;
        }
        
        .modal.show {
            display: flex;
        }
        
        .modal-content {
            max-width: 90%;
            max-height: 90%;
            position: relative;
        }
        
        .modal-image {
            width: 100%;
            height: auto;
            border-radius: 10px;
        }
        
        .modal-close {
            position: absolute;
            top: -50px;
            right: 0;
            color: white;
            font-size: 2rem;
            cursor: pointer;
            transition: opacity 0.3s ease;
        }
        
        .modal-close:hover {
            opacity: 0.7;
        }
        
        .modal-nav {
            position: absolute;
            top: 50%;
            transform: translateY(-50%);
            color: white;
            font-size: 2rem;
            cursor: pointer;
            padding: 20px;
            transition: opacity 0.3s ease;
        }
        
        .modal-nav:hover {
            opacity: 0.7;
        }
        
        .modal-prev {
            left: -60px;
        }
        
        .modal-next {
            right: -60px;
        }
        
        /* Responsive Design */
        @media (max-width: 768px) {
            .gallery-controls {
                flex-direction: column;
                gap: 20px;
            }
            
            .gallery-stats {
                justify-content: center;
            }
            
            .gallery-grid {
                grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
                gap: 20px;
            }
            
            .modal-nav {
                display: none;
            }
        }
    </style>
</head>
<body>
    <div class="gallery-container">
        <!-- Header -->
        <div class="gallery-header">
            <h1><i class="fas fa-images"></i> My Crop Photo Gallery</h1>
            <p>Showcase your beautiful crops to potential buyers</p>
        </div>
        
        <!-- Controls -->
        <div class="gallery-controls">
            <div class="gallery-stats">
                <div class="stat-item">
                    <div class="stat-number"><?php echo count($gallery_items); ?></div>
                    <div class="stat-label">Total Photos</div>
                </div>
                <div class="stat-item">
                    <div class="stat-number">
                        <?php 
                        $active_count = 0;
                        foreach ($gallery_items as $item) {
                            if ($item['status'] === 'active') $active_count++;
                        }
                        echo $active_count;
                        ?>
                    </div>
                    <div class="stat-label">Active Listings</div>
                </div>
                <div class="stat-item">
                    <div class="stat-number">
                        <?php 
                        $crops = array_unique(array_column($gallery_items, 'crop_name'));
                        echo count($crops);
                        ?>
                    </div>
                    <div class="stat-label">Crop Types</div>
                </div>
            </div>
            
            <div class="gallery-filters">
                <button class="filter-btn active" data-filter="all">All</button>
                <button class="filter-btn" data-filter="active">Active</button>
                <button class="filter-btn" data-filter="sold">Sold</button>
                <button class="filter-btn" data-filter="expired">Expired</button>
            </div>
        </div>
        
        <!-- Gallery Grid -->
        <?php if (count($gallery_items) > 0): ?>
        <div class="gallery-grid" id="galleryGrid">
            <?php foreach ($gallery_items as $item): 
                $images = json_decode($item['images'], true);
                if ($images && count($images) > 0): ?>
                <div class="gallery-card" data-status="<?php echo $item['status']; ?>" data-images='<?php echo htmlspecialchars($item['images']); ?>'>
                    <div class="gallery-image">
                        <img src="../uploads/<?php echo htmlspecialchars($images[0]); ?>" 
                             alt="<?php echo htmlspecialchars($item['title']); ?>"
                             onclick="openModal(this, '<?php echo htmlspecialchars($item['images']); ?>')">
                        <div class="image-overlay">
                            <div class="image-info">
                                <div style="font-weight: 600;"><?php echo htmlspecialchars($item['title']); ?></div>
                                <div style="opacity: 0.9;"><?php echo htmlspecialchars($item['crop_name']); ?></div>
                            </div>
                        </div>
                        <div class="image-actions">
                            <?php if (count($images) > 1): ?>
                            <div class="action-icon" title="Multiple images">
                                <i class="fas fa-images"></i>
                            </div>
                            <?php endif; ?>
                            <div class="action-icon" onclick="openModal(this.closest('.gallery-card').querySelector('img'), '<?php echo htmlspecialchars($item['images']); ?>')" title="View full size">
                                <i class="fas fa-expand"></i>
                            </div>
                        </div>
                    </div>
                    <div class="card-content">
                        <div class="card-title"><?php echo htmlspecialchars($item['title']); ?></div>
                        <div class="card-crop"><i class="fas fa-leaf"></i> <?php echo htmlspecialchars($item['crop_name']); ?></div>
                        <?php if ($item['description']): ?>
                        <div class="card-description"><?php echo htmlspecialchars(substr($item['description'], 0, 100)) . (strlen($item['description']) > 100 ? '...' : ''); ?></div>
                        <?php endif; ?>
                        <div class="card-meta">
                            <span><?php echo date('M j, Y', strtotime($item['created_at'])); ?></span>
                            <span class="status-badge status-<?php echo $item['status']; ?>">
                                <?php echo ucfirst($item['status']); ?>
                            </span>
                        </div>
                    </div>
                </div>
            <?php endif; endforeach; ?>
        </div>
        <?php else: ?>
        <div class="no-photos">
            <i class="fas fa-camera"></i>
            <h3>No Photos Yet</h3>
            <p>Start showcasing your crops by adding products with photos to your marketplace.</p>
            <a href="add-product.php" class="btn">
                <i class="fas fa-plus"></i> Add Your First Product
            </a>
        </div>
        <?php endif; ?>
        
        <!-- Navigation -->
        <div style="text-align: center; margin-top: 30px;">
            <a href="dashboard.php" class="btn" style="background: #6c757d;">
                <i class="fas fa-arrow-left"></i> Back to Dashboard
            </a>
        </div>
    </div>
    
    <!-- Modal for image viewing -->
    <div id="imageModal" class="modal">
        <div class="modal-content">
            <span class="modal-close" onclick="closeModal()">&times;</span>
            <div class="modal-nav modal-prev" onclick="prevImage()">&#10094;</div>
            <img id="modalImage" class="modal-image" src="" alt="">
            <div class="modal-nav modal-next" onclick="nextImage()">&#10095;</div>
        </div>
    </div>
    
    <script>
        let currentImages = [];
        let currentImageIndex = 0;
        
        // Filter functionality
        document.querySelectorAll('.filter-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                // Update active button
                document.querySelectorAll('.filter-btn').forEach(b => b.classList.remove('active'));
                this.classList.add('active');
                
                // Filter cards
                const filter = this.getAttribute('data-filter');
                const cards = document.querySelectorAll('.gallery-card');
                
                cards.forEach(card => {
                    if (filter === 'all' || card.getAttribute('data-status') === filter) {
                        card.style.display = 'block';
                    } else {
                        card.style.display = 'none';
                    }
                });
            });
        });
        
        // Modal functionality
        function openModal(imgElement, imagesJson) {
            const modal = document.getElementById('imageModal');
            const modalImg = document.getElementById('modalImage');
            
            currentImages = JSON.parse(imagesJson);
            currentImageIndex = 0;
            
            modalImg.src = '../uploads/' + currentImages[currentImageIndex];
            modal.classList.add('show');
            
            // Prevent body scroll
            document.body.style.overflow = 'hidden';
        }
        
        function closeModal() {
            const modal = document.getElementById('imageModal');
            modal.classList.remove('show');
            document.body.style.overflow = 'auto';
        }
        
        function nextImage() {
            if (currentImageIndex < currentImages.length - 1) {
                currentImageIndex++;
                document.getElementById('modalImage').src = '../uploads/' + currentImages[currentImageIndex];
            }
        }
        
        function prevImage() {
            if (currentImageIndex > 0) {
                currentImageIndex--;
                document.getElementById('modalImage').src = '../uploads/' + currentImages[currentImageIndex];
            }
        }
        
        // Keyboard navigation
        document.addEventListener('keydown', function(e) {
            if (document.getElementById('imageModal').classList.contains('show')) {
                if (e.key === 'Escape') {
                    closeModal();
                } else if (e.key === 'ArrowRight') {
                    nextImage();
                } else if (e.key === 'ArrowLeft') {
                    prevImage();
                }
            }
        });
        
        // Close modal when clicking outside
        document.getElementById('imageModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeModal();
            }
        });
    </script>
</body>
</html>