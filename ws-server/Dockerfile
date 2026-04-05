FROM node:20-alpine

WORKDIR /app

# Instalar dependencias primero (mejor cache de Docker)
COPY package.json ./
RUN npm install --omit=dev

COPY index.js ./

EXPOSE 6001

# Usuario no-root
USER node

CMD ["node", "index.js"]
