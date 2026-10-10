# 🎵 Spotify Distributor & Metadata Finder

Spotify şarkılarının, albümlerinin, sanatçılarının ve çalma listelerinin arkasındaki **dijital müzik dağıtımcısını** (DistroKid, TuneCore, CD Baby, The Orchard, Believe, Sony, Universal, Warner vb.) ve ISRC, UPC, şirket (label), çıkış tarihi gibi meta verilerini tespit eden açık kaynak PHP API motoru.

Tüm dağıtımcı verileri veritabanı olmadan doğrudan [`distributor.json`](distributor.json) dosyasından okunur.

---

## 🚀 Özellikler

- **112+ Dağıtımcı Veri Seti:** Tüm dağıtımcılar [`distributor.json`](distributor.json) üzerinden anında bellekten $O(1)$ hızında eşleştirilir.
- **Dağıtımcı Tespiti:** Spotify parçalarından licensor UUID ve ISRC verisini çözümleyerek dağıtımcı şirketini ve web sitesini bulur.
- **Parça & Albüm Detayları:** ISRC, UPC, telif hakları (P-line / C-line), plak şirketi (label), kapak görseli ve süre bilgileri.
- **Toplu Sorgu (Batch):** Tek istekte 50 parçaya kadar toplu dağıtımcı sorgulama.
- **JSON / Zero-DB:** Veritabanı gerektirmez, Spotify token'larını otomatik olarak geçici dosya önbelleğinde saklar.

---

## 📁 Proje Yapısı

```text
├── api/
│   ├── distrofinder_api.php       # Şarkı & Dağıtımcı sorgulama motoru
│   ├── album_api.php              # Albüm detay & parça listesi sorgusu
│   ├── artist_api.php             # Sanatçı kataloğu sorgusu
│   ├── playlist_api.php           # Çalma listesi dağıtımcı analiz sorgusu
│   ├── batch_distro.php           # Toplu parça dağıtımcı sorgusu
│   ├── batch_label_distro.php     # Toplu label/albüm sorgusu
│   └── .htaccess                  # API URL yönlendirmeleri
├── core/
│   ├── config.example.php         # Ayar şablonu
│   ├── config.php                 # Spotify API anahtarları (gitignore korumalı)
│   ├── init.php                   # Çekirdek bootstrap & token önbellek motoru
│   ├── distro_info.php            # distributor.json okuyucu motor
│   └── engine.php                 # Spotify Web API bağlantı istemcisi
├── distributor.json               # 112+ Doğrulanmış dağıtımcı veri seti
├── index.php                      # API durum gateway çıktısı
├── .gitignore
├── .htaccess
└── LICENSE                        # MIT Lisansı
```

---

## ⚙️ Kurulum

### 1. Gereksinimler
- **PHP 8.0+** (`curl`, `json`, `openssl` eklentileri açık)
- [Spotify Developer Dashboard](https://developer.spotify.com/dashboard)'dan alınmış **Client ID** ve **Client Secret**

### 2. Başlatma

1. Depoyu klonlayın:
   ```bash
   git clone https://github.com/kullanici-adiniz/spotify-distro-finder.git
   cd spotify-distro-finder
   ```

2. Ayar dosyasını oluşturun:
   ```bash
   cp core/config.example.php core/config.php
   ```

3. `core/config.php` dosyasını açıp Spotify bilgilerinizi yazın:
   ```php
   return [
       'spotify_client_id'     => 'SPOTIFY_CLIENT_ID_BURAYA',
       'spotify_client_secret' => 'SPOTIFY_CLIENT_SECRET_BURAYA',
   ];
   ```

4. Yerel sunucuyu başlatın:
   ```bash
   php -S localhost:8000
   ```

---

## 📡 Endpoint'ler ve Kullanım

### 1. Parça & Dağıtımcı Sorgulama
```http
GET /api/distrofinder_api.php?id={SPOTIFY_TRACK_ID_VEYA_URL}&action=distro
```

**Örnek İstek:**
```bash
curl "http://localhost:8000/api/distrofinder_api.php?id=4cOdK2wGLETKBW3PvgPWqT&action=distro"
```

**Örnek JSON Yanıtı:**
```json
{
  "success": true,
  "distributor": "Sony Music Entertainment",
  "distro_website": "https://sonymusic.com",
  "upload_date": "1987-11-12",
  "licensor_uuid": "aa946853-9d19-400c-a4c3-2fdc159926a9",
  "label": "RCA Records Label"
}
```

---

### 2. Albüm Sorgulama
```http
GET /api/album_api.php?id={SPOTIFY_ALBUM_ID_VEYA_URL}
```

---

### 3. Sanatçı Sorgulama
```http
GET /api/artist_api.php?id={SPOTIFY_ARTIST_ID_VEYA_URL}
```

---

### 4. Çalma Listesi Analizi
```http
GET /api/playlist_api.php?id={SPOTIFY_PLAYLIST_ID_VEYA_URL}
```

---

### 5. Toplu Parça Dağıtımcı Sorgusu (Batch)
```http
POST /api/batch_distro.php
Content-Type: application/json
```

**Gövde:**
```json
{
  "track_ids": [
    "4cOdK2wGLETKBW3PvgPWqT",
    "0VjIjW4GlUZAMYd2vXMi3b",
    "3n3Ppam7vgaVa1iaRUc9Lp"
  ]
}
```

---

## 📄 Lisans

Bu proje [MIT Lisansı](LICENSE) ile lisanslanmıştır.
