FROM python:3.12-slim

ENV PYTHONDONTWRITEBYTECODE=1 \
    PYTHONUNBUFFERED=1

WORKDIR /app
COPY docker/radius-policy-bridge/server.py /app/server.py

USER 10001:10001
EXPOSE 8080

CMD ["python", "/app/server.py"]
