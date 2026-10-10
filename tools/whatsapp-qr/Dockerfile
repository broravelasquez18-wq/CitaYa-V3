# Servicio de WhatsApp por QR (Baileys) para CitaYa V3. Solo en la red interna del compose: sin puertos publicados.
FROM node:22-alpine
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci --omit=dev && npm cache clean --force
COPY server.mjs inbox.mjs ./
# .session (credenciales de WhatsApp, bandeja de entrada y chats) va en un volumen persistente.
RUN mkdir -p /app/.session && chown -R node:node /app/.session
ENV WA_HOST=0.0.0.0 WA_PRINT_QR=1 NODE_ENV=production
USER node
EXPOSE 3218
CMD ["node", "server.mjs"]
