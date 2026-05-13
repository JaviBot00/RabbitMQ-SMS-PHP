# Imagen base oficial con el plugin delayed message habilitado.
# El plugin permite retrasar la entrega de mensajes (usuarios especiales).
FROM rabbitmq:3.13-management

# RUN rabbitmq-plugins enable --offline rabbitmq_delayed_message_exchange
# El plugin delayed NO viene en la imagen base, hay que descargarlo
# La versión del .ez debe coincidir con la versión de RabbitMQ (3.13.x)
RUN apt-get update && apt-get install -y curl && \
    curl -L https://github.com/rabbitmq/rabbitmq-delayed-message-exchange/releases/download/v3.13.0/rabbitmq_delayed_message_exchange-3.13.0.ez \
    -o /opt/rabbitmq/plugins/rabbitmq_delayed_message_exchange-3.13.0.ez && \
    rabbitmq-plugins enable --offline rabbitmq_delayed_message_exchange
