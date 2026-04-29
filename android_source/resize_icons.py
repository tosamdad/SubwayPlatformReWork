import os
import subprocess
import sys

# Pillow 설치 시도
try:
    from PIL import Image
except ImportError:
    print("Pillow not found. Installing...")
    subprocess.check_call([sys.executable, "-m", "pip", "install", "Pillow"])
    from PIL import Image

# 경로 설정
base_dir = os.path.dirname(os.path.abspath(__file__))
src_icon = os.path.join(base_dir, "앱사용예정이미지", "밝은 버전", "앱아이콘_밝은버전_1024x1024.png")
src_splash = os.path.join(base_dir, "앱사용예정이미지", "밝은 버전", "스플래시화면_밝은버전_1080x1920.png")

res_dir = os.path.join(base_dir, "SubwayPlatformApp", "app", "src", "main", "res")

# 1. 스플래시 이미지 복사 및 변환
print("Processing splash screen...")
if os.path.exists(src_splash):
    drawable_dir = os.path.join(res_dir, "drawable")
    os.makedirs(drawable_dir, exist_ok=True)
    img_splash = Image.open(src_splash)
    img_splash.save(os.path.join(drawable_dir, "splash.png"))
    print("Splash screen saved to drawable/splash.png")
else:
    print(f"Error: Splash image not found at {src_splash}")

# 2. 앱 아이콘 리사이징
print("Processing app icons...")
if os.path.exists(src_icon):
    sizes = {
        "mipmap-mdpi": 48,
        "mipmap-hdpi": 72,
        "mipmap-xhdpi": 96,
        "mipmap-xxhdpi": 144,
        "mipmap-xxxhdpi": 192
    }
    
    img_icon = Image.open(src_icon)
    
    for folder, size in sizes.items():
        folder_path = os.path.join(res_dir, folder)
        os.makedirs(folder_path, exist_ok=True)
        
        # 일반 아이콘
        resized = img_icon.resize((size, size), Image.Resampling.LANCZOS)
        resized.save(os.path.join(folder_path, "ic_launcher.png"))
        
        # 원형 아이콘 (동일 이미지 사용)
        resized.save(os.path.join(folder_path, "ic_launcher_round.png"))
        
        print(f"Icon {size}x{size} saved to {folder}/")
else:
    print(f"Error: Icon image not found at {src_icon}")

print("Image processing complete.")
