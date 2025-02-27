# import os
# import shutil
#
# START_FOLDER = 402
# END_FOLDER = 405
# BASE_PORT = 9000
# SOURCE_FOLDER = '00001'
# INI_FILENAME = 'posapi.ini'
#
#
# def update_posapi_ini(folder_path, folder_name, port_number):
#     ini_path = os.path.join(folder_path, INI_FILENAME)
#     with open(ini_path, 'r') as file:
#         content = file.read()
#     content = content.replace('name=vatps_00001.db', f'name=vatps_{folder_name}.db')
#     content = content.replace('port=9001', f'port={port_number}')
#     with open(ini_path, 'w') as file:
#         file.write(content)
#
#
# for i in range(START_FOLDER, END_FOLDER + 1):
#     folder_name = f'{i:05}'
#     dest_folder = folder_name
#     port_number = BASE_PORT + i
#
#     shutil.copytree(SOURCE_FOLDER, dest_folder)
#     update_posapi_ini(dest_folder, folder_name, port_number)
#
#     print(f'{folder_name}: completed')
#
# print("All folders have been processed.")

import os
import shutil

START_FOLDER = 451
END_FOLDER = 900
BASE_PORT = 9000
SOURCE_FOLDER = '00001'
INI_FILENAME = 'posapi.ini'
# DOCKERFILE_NAME = 'Dockerfile'
# DOCKER_COMPOSE_NAME = 'docker-compose.yaml'
# ENTRYPOINT_SCRIPT_NAME = 'entrypoint.sh'
#
# dockerfile_path = os.path.join(os.getcwd(), DOCKERFILE_NAME)
# docker_compose_path = os.path.join(os.getcwd(), DOCKER_COMPOSE_NAME)
# entrypoint_script_path = os.path.join(os.getcwd(), ENTRYPOINT_SCRIPT_NAME)


def update_posapi_ini(folder_path, folder_name, port_number):
    ini_path = os.path.join(folder_path, INI_FILENAME)
    with open(ini_path, 'r') as file:
        content = file.read()
    content = content.replace('name=vatps_00001.db', f'name=vatps_{folder_name}.db')
    content = content.replace('port=9001', f'port={port_number}')
    with open(ini_path, 'w') as file:
        file.write(content)


for i in range(START_FOLDER, END_FOLDER + 1):
    folder_name = f'{i:05}'
    dest_folder = folder_name
    port_number = BASE_PORT + i

    shutil.copytree(SOURCE_FOLDER, dest_folder)
    update_posapi_ini(dest_folder, folder_name, port_number)

    # with open(dockerfile_path, 'a') as file:
    #     file.write(f'RUN chmod +x /app/{folder_name}/PosService\n')
    #
    # with open(docker_compose_path, 'a') as file:
    #     file.write(f'      - "{port_number}:{port_number}"\n')
    #
    # with open(entrypoint_script_path, 'a') as file:
    #     file.write(f'/app/{folder_name}/PosService &\n')

    print(f'{folder_name}: completed')

print("All folders have been processed.")
