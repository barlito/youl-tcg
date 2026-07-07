# Holo mask generator — rembg (background removal) in a self-contained image.
# Build:  docker build -t ytcg-holo tools/holo
# Run:    docker run --rm -v "$PWD:/work" ytcg-holo public/images/cards/foo.png
FROM python:3.12-slim

RUN pip install --no-cache-dir "rembg[cpu]" pillow

# Pre-download the U2Net model so runs are fast and offline.
RUN python -c "from rembg import new_session; new_session('u2net')"

WORKDIR /work
COPY make_masks.py /opt/make_masks.py
ENTRYPOINT ["python", "/opt/make_masks.py"]
