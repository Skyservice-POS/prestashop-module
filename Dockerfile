FROM nginx:alpine

# Копіюємо index.html в директорію nginx
COPY index.html /usr/share/nginx/html/index.html

# Відкриваємо порт 80
EXPOSE 80

# Nginx запускається автоматично
CMD ["nginx", "-g", "daemon off;"]
